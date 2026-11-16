<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\TransactionIsolationLevel;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;
use SensitiveParameter;
use Throwable;

/** Current-password self-service or fresh authorized administrator; durable cancellable deletion requests. */
final class AccountPrivacy
{
    public const GRACE_DAYS = 14;

    public function __construct(
        private readonly Connection $connection,
        private readonly PasswordHasher $hasher,
        private readonly RateBuckets $buckets,
        private readonly PersonalDataExport $exports,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function pending(SubjectPermissions $subject, int $target, bool $freshAdmin): ?array
    {
        $this->access($subject, $target, $freshAdmin);
        $row = $this->connection->fetchAssociative(
            'SELECT requested_at, delete_after, auto_finalize, processing_started_at FROM account_deletions '
                . 'WHERE user_id = ?',
            [$target],
        );

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public function due(SubjectPermissions $subject, bool $freshAdmin): array
    {
        if (!$freshAdmin || !$subject->allows('admin.access')) {
            throw new RegistrationRejected(['privacy_denied']);
        }

        return $this->connection->fetchAllAssociative(
            'SELECT user_id, requested_at, delete_after FROM account_deletions '
                . 'WHERE delete_after <= UTC_TIMESTAMP(6) ORDER BY delete_after, user_id LIMIT 25',
        );
    }

    public function change(
        SubjectPermissions $subject,
        int $target,
        bool $freshAdmin,
        #[SensitiveParameter]
        string $password,
        string $action,
    ): bool {
        $this->access($subject, $target, $freshAdmin);
        $this->attempt($subject->userId);

        return $this->connection->transactional(function (Connection $db) use (
            $subject,
            $target,
            $password,
            $action,
        ): bool {
            // Same group-first order as first-admin provisioning; serializes protection of the last administrator.
            $db->fetchOne('SELECT id FROM usergroups WHERE id = ? FOR UPDATE', [BuiltInGroup::Administrators->value]);
            $user = $db->fetchAssociative(
                'SELECT id, password_hash, status FROM users WHERE id = ? FOR UPDATE',
                [$target],
            );
            $this->password($subject, $target, $password, $user);
            $request = $db->fetchAssociative(
                'SELECT delete_after FROM account_deletions WHERE user_id = ? FOR UPDATE',
                [$target],
            );
            $now = $this->now();

            if ($action === 'cancel') {
                if ($request !== false && $request['delete_after'] <= $now->format('Y-m-d H:i:s.u')) {
                    throw new RegistrationRejected(['privacy_grace_expired']);
                }

                $db->delete('account_deletions', ['user_id' => $target]);
                $this->audit($subject->userId, $target, 'user.deletion_cancelled');

                return false;
            }

            $isAdmin = $db->fetchOne(
                'SELECT 1 FROM usergroup_members WHERE user_id = ? AND usergroup_id = ?',
                [$target, BuiltInGroup::Administrators->value],
            ) !== false;
            $otherAdmin = $db->fetchOne(
                'SELECT 1 FROM usergroup_members m INNER JOIN users u ON u.id = m.user_id '
                    . "WHERE m.usergroup_id = ? AND m.user_id <> ? AND u.status = 'active' LIMIT 1",
                [BuiltInGroup::Administrators->value, $target],
            ) !== false;

            if ($isAdmin && !$otherAdmin) {
                throw new RegistrationRejected(['privacy_last_admin']);
            }

            if ($action === 'request') {
                if ($request === false) {
                    $db->insert('account_deletions', [
                        'user_id' => $target,
                        'requested_by' => $subject->userId,
                        'auto_finalize' => 1,
                        'requested_at' => $now->format('Y-m-d H:i:s.u'),
                        'delete_after' => $now->modify('+' . self::GRACE_DAYS . ' days')->format('Y-m-d H:i:s.u'),
                    ]);
                    (new Outbox($db))->record(
                        'user.deletion_requested',
                        'user',
                        $target,
                        ['requested_at' => $now->format('Y-m-d H:i:s.u')],
                        'account.delete:' . $target . ':' . $now->format('YmdHisu'),
                        $now->modify('+' . self::GRACE_DAYS . ' days'),
                    );
                    $this->audit($subject->userId, $target, 'user.deletion_requested');
                }

                return false;
            }

            if (
                $action !== 'finalize' || $request === false
                || $request['delete_after'] > $now->format('Y-m-d H:i:s.u')
            ) {
                throw new RegistrationRejected(['privacy_not_due']);
            }

            // Manual finalization also schedules bounded work; never cascade an unbounded account in this request.
            $db->update('account_deletions', ['auto_finalize' => 1], ['user_id' => $target]);
            $key = 'account.finalize:' . $target . ':' . $request['delete_after'];
            $known = $db->fetchOne('SELECT status FROM outbox_events WHERE dedupe_key = ?', [$key]);

            if ($known === false) {
                (new Outbox($db))->record(
                    'user.deletion_requested',
                    'user',
                    $target,
                    ['requested_at' => $db->fetchOne(
                        'SELECT requested_at FROM account_deletions WHERE user_id = ?',
                        [$target],
                    )],
                    $key,
                    $now,
                );
            } elseif ($known === 'failed') {
                $db->executeStatement(
                    "UPDATE outbox_events SET status = 'pending', attempts = 0, owner_token = NULL, "
                        . 'lease_expires_at = NULL, available_at = ?, revision = revision + 1, last_error = NULL '
                        . "WHERE dedupe_key = ? AND status = 'failed'",
                    [$now->format('Y-m-d H:i:s.u'), $key],
                );
            }

            $this->audit($subject->userId, $target, 'user.deletion_finalized');

            return false;
        });
    }

    public function export(
        SubjectPermissions $subject,
        int $target,
        bool $freshAdmin,
        #[SensitiveParameter]
        string $password,
    ): ?string {
        $this->access($subject, $target, $freshAdmin);
        $this->attempt($subject->userId);

        if ($this->connection->isTransactionActive()) {
            throw new RegistrationRejected(['privacy_export_failed']);
        }

        $previous = $this->connection->getTransactionIsolation();
        $this->connection->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        $path = null;

        try {
            $this->connection->transactional(function (Connection $db) use (
                $subject,
                $target,
                $password,
                &$path,
            ): void {
                $user = $db->fetchAssociative(
                    'SELECT id, password_hash, status FROM users WHERE id = ? FOR UPDATE',
                    [$target],
                );
                $this->password($subject, $target, $password, $user);
                $processing = $db->fetchOne(
                    'SELECT processing_started_at FROM account_deletions WHERE user_id = ?',
                    [$target],
                );

                if (is_string($processing)) {
                    throw new RegistrationRejected(['privacy_deletion_in_progress']);
                }

                $path = $this->exports->resumable()->request($subject->userId, $target, $subject->userId === $target);
                $this->audit(
                    $subject->userId,
                    $target,
                    $path === null ? 'user.data_export_requested' : 'user.data_exported',
                );
            });

            return $path;
        } catch (Throwable $failure) {
            if (is_string($path)) {
                @unlink($path);
            }

            throw $failure;
        } finally {
            $this->connection->setTransactionIsolation($previous);
        }
    }

    public function exportProgress(SubjectPermissions $subject, int $target, bool $freshAdmin): ?array
    {
        $this->access($subject, $target, $freshAdmin);

        return $this->exports->resumable()->progress($subject->userId, $target, $subject->userId === $target);
    }

    /** Internal registered task only. The runner owns the transaction and fenced queue row. */
    public function finalizeScheduled(int $target, string $requestedAt, Closure $guard): bool
    {
        $guard();
        $db = $this->connection;
        $db->fetchOne('SELECT id FROM usergroups WHERE id = ? FOR UPDATE', [BuiltInGroup::Administrators->value]);
        $user = $db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$target]);
        $request = $db->fetchAssociative(
            'SELECT requested_at, delete_after, auto_finalize, processing_started_at, cleanup_plan, protect_admin '
                . 'FROM account_deletions WHERE user_id = ? FOR UPDATE',
            [$target],
        );

        if (
            $user === false || $request === false || (int) $request['auto_finalize'] !== 1
            || $request['requested_at'] !== $requestedAt
        ) {
            return true;
        }

        if ($request['delete_after'] > $this->now()->format('Y-m-d H:i:s.u')) {
            throw new RegistrationRejected(['privacy_not_due']);
        }

        return (new AccountDeletionBatch($db))->step($target, $request, $guard);
    }

    private function access(SubjectPermissions $subject, int $target, bool $freshAdmin): void
    {
        if (
            $subject->userId === null || $target < 1
            || ($target !== $subject->userId && (!$freshAdmin || !$subject->allows('admin.access')))
        ) {
            throw new RegistrationRejected(['privacy_denied']);
        }
    }

    /** @param array<string, mixed>|false $user */
    private function password(
        SubjectPermissions $subject,
        int $target,
        #[SensitiveParameter]
        string $password,
        array|false $user,
    ): void {
        if (
            $user === false || ($subject->userId === $target && ($user['status'] !== 'active'
            || !$this->hasher->verify($password, $user['password_hash'])))
        ) {
            throw new RegistrationRejected(['current_password_invalid']);
        }
    }

    private function attempt(int $actor): void
    {
        $now = $this->now();
        $key = 'account.privacy:' . $actor;
        $this->buckets->ensure($key, $now);
        $wait = $this->buckets->hit($key, 5, $now);

        if ($wait > 0) {
            throw new RegistrationThrottled($wait);
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string) $this->connection->fetchOne('SELECT UTC_TIMESTAMP(6)'),
            new DateTimeZone('UTC')
        );
    }

    private function audit(int $actor, int $target, string $action): void
    {
        $this->connection->insert('audit_logs', [
            'actor_id' => $actor,
            'action' => $action,
            'target_type' => 'user',
            'target_id' => $target,
            'reason' => null,
            'details' => null,
            'created_at' => $this->now()->format('Y-m-d H:i:s.u'),
        ]);
    }
}
