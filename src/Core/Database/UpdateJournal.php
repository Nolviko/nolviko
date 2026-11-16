<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nolviko\Core\Updates\UpdateRun;
use Nolviko\Core\Updates\UpdateStage;
use RuntimeException;
use SensitiveParameter;

/**
 * Durable, compare-and-swap update checkpoints with one active run and fenced request leases (M09.06).
 * No download, migration or filesystem side effect runs here; callers checkpoint only completed bounded work.
 */
final class UpdateJournal
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function begin(string $target, string $manifestHash, DateTimeImmutable $now): string
    {
        if (
            preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D', $target) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $manifestHash) !== 1
        ) {
            throw new RuntimeException('update_identity_invalid');
        }

        if ($this->connection->isTransactionActive()) {
            throw new RuntimeException('update_begin_transaction_active');
        }

        $id = bin2hex(random_bytes(16));

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                $this->connection->insert('update_runs', [
                    'id' => $id,
                    'active_slot' => 1,
                    'target' => $target,
                    'manifest_hash' => $manifestHash,
                    'stage' => UpdateStage::Discovered->value,
                    'run_condition' => 'running',
                    'created_at' => self::stamp($now),
                    'updated_at' => self::stamp($now),
                ]);

                return $id;
            } catch (UniqueConstraintViolationException) {
                throw new RuntimeException('update_already_active');
            } catch (RetryableException) {
                // The complete autocommit insert was rolled back; never retry a fragment of a caller transaction.
                continue;
            }
        }

        throw new RuntimeException('update_begin_busy');
    }

    public function find(string $id): ?UpdateRun
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, target, manifest_hash, stage, checkpoint_cursor, revision, run_condition, diagnostic '
                . 'FROM update_runs WHERE id = ?',
            [$id],
        );

        return $row === false ? null : new UpdateRun(
            (string) $row['id'],
            (string) $row['target'],
            (string) $row['manifest_hash'],
            UpdateStage::from((string) $row['stage']),
            (int) $row['checkpoint_cursor'],
            (int) $row['revision'],
            (string) $row['run_condition'],
            $row['diagnostic'] === null ? null : (string) $row['diagnostic'],
        );
    }

    /**
     * An explicit recovery action may resume a paused/failed run; ordinary requests cannot do so silently.
     * Returned token is an internal credential, never a URL parameter or a public status field.
     */
    public function claim(string $id, DateTimeImmutable $now, int $seconds = 120, bool $recover = false): string
    {
        if ($seconds < 5 || $seconds > 300) {
            throw new RuntimeException('update_lease_duration_invalid');
        }

        $token = bin2hex(random_bytes(32));
        $updated = $this->connection->executeStatement(
            'UPDATE update_runs SET owner_token = ?, lease_expires_at = ?, revision = revision + 1, '
                . "run_condition = 'running', diagnostic = NULL, updated_at = ? "
                . 'WHERE id = ? AND active_slot = 1 AND (owner_token IS NULL OR lease_expires_at <= ?)'
                . ($recover
                    ? " AND run_condition IN ('running', 'paused', 'recoverable', 'manual_attention')"
                    : " AND run_condition = 'running'"),
            [$token, self::stamp($now->modify('+' . $seconds . ' seconds')), self::stamp($now), $id, self::stamp($now)],
        );

        if ($updated !== 1) {
            throw new RuntimeException('update_claim_refused');
        }

        return $token;
    }

    public function release(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        DateTimeImmutable $now,
    ): UpdateRun {
        return $this->change($id, $token, $revision, $now, [
            'owner_token' => null,
            'lease_expires_at' => null,
        ]);
    }

    public function renew(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        DateTimeImmutable $now,
        int $seconds = 120,
    ): UpdateRun {
        if ($seconds < 5 || $seconds > 300) {
            throw new RuntimeException('update_lease_duration_invalid');
        }

        return $this->change($id, $token, $revision, $now, [
            'lease_expires_at' => self::stamp($now->modify('+' . $seconds . ' seconds')),
        ]);
    }

    /**
     * Records a monotonic cursor within this stage; stale revisions are rejected even for the same holder.
     */
    public function checkpoint(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        int $cursor,
        DateTimeImmutable $now,
    ): UpdateRun {
        $run = $this->find($id) ?? throw new RuntimeException('update_not_found');

        if ($cursor <= $run->cursor || $cursor < 0) {
            throw new RuntimeException('update_cursor_invalid');
        }

        return $this->change($id, $token, $revision, $now, ['checkpoint_cursor' => $cursor]);
    }

    public function advance(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        UpdateStage $next,
        DateTimeImmutable $now,
    ): UpdateRun {
        $run = $this->find($id) ?? throw new RuntimeException('update_not_found');

        if ($run->stage->next() !== $next) {
            throw new RuntimeException('update_transition_invalid');
        }

        $changes = ['stage' => $next->value, 'checkpoint_cursor' => 0];

        if ($next === UpdateStage::Completed) {
            $changes += ['active_slot' => null, 'owner_token' => null, 'lease_expires_at' => null];
        }

        return $this->change($id, $token, $revision, $now, $changes);
    }

    /**
     * Cancel only before production mutation; later recovery must prove a coherent restore, not just unlock.
     */
    public function abandon(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        DateTimeImmutable $now,
    ): UpdateRun {
        $run = $this->find($id) ?? throw new RuntimeException('update_not_found');

        $beforeMaintenance = [
            UpdateStage::Discovered,
            UpdateStage::Checked,
            UpdateStage::Downloaded,
            UpdateStage::Verified,
            UpdateStage::Staged,
            UpdateStage::BackedUp,
        ];

        if (!in_array($run->stage, $beforeMaintenance, true)) {
            throw new RuntimeException('update_abandon_unsafe');
        }

        return $this->change($id, $token, $revision, $now, [
            'active_slot' => null,
            'owner_token' => null,
            'lease_expires_at' => null,
            'run_condition' => 'abandoned',
        ]);
    }

    public function fail(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        string $condition,
        string $diagnostic,
        DateTimeImmutable $now,
    ): UpdateRun {
        if (
            !in_array($condition, ['paused', 'recoverable', 'manual_attention'], true)
            || preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $diagnostic) !== 1
        ) {
            throw new RuntimeException('update_failure_invalid');
        }

        return $this->change($id, $token, $revision, $now, [
            'run_condition' => $condition,
            'diagnostic' => $diagnostic,
            'owner_token' => null,
            'lease_expires_at' => null,
        ]);
    }

    /**
     * Verify ownership without changing the revision; safe while backup holds READ locks.
     */
    public function assertBackupLease(string $id, #[SensitiveParameter] string $token, int $revision): void
    {
        $owned = $this->connection->fetchOne(
            'SELECT 1 FROM update_runs WHERE id = ? AND owner_token = ? AND revision = ? '
                . "AND stage = 'staged' AND active_slot = 1 AND run_condition = 'running' "
                . 'AND lease_expires_at > UTC_TIMESTAMP(6)',
            [$id, $token, $revision],
        );

        if ($owned === false) {
            throw new RuntimeException('update_backup_lease_lost');
        }
    }

    public function recordBackup(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        string $backupId,
        string $digest,
    ): UpdateRun {
        $this->assertBackupLease($id, $token, $revision);

        if (preg_match('/^[a-f0-9]{32}$/D', $backupId) !== 1 || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new RuntimeException('update_backup_identity_invalid');
        }

        $updated = $this->connection->executeStatement(
            'UPDATE update_runs SET backup_id = ?, backup_digest = ?, stage = ?, checkpoint_cursor = 0, '
                . 'revision = revision + 1, updated_at = UTC_TIMESTAMP(6) '
                . 'WHERE id = ? AND owner_token = ? AND revision = ? AND active_slot = 1 '
                . "AND run_condition = 'running' AND stage = 'staged' AND lease_expires_at > UTC_TIMESTAMP(6)",
            [$backupId, $digest, UpdateStage::BackedUp->value, $id, $token, $revision],
        );

        if ($updated !== 1) {
            throw new RuntimeException('update_backup_lease_lost');
        }

        return $this->find($id) ?? throw new RuntimeException('update_not_found');
    }

    /**
     * @return array{id: string, digest: string}|null
     */
    public function backup(string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT backup_id, backup_digest FROM update_runs WHERE id = ?',
            [$id],
        );

        return $row === false || $row['backup_id'] === null
            ? null
            : ['id' => $row['backup_id'], 'digest' => $row['backup_digest']];
    }

    public function assertFileSwitchLease(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        string $manifestHash,
    ): void {
        $owned = $this->connection->fetchOne(
            'SELECT 1 FROM update_runs WHERE id = ? AND owner_token = ? AND revision = ? AND manifest_hash = ? '
                . "AND stage = 'maintenance' AND active_slot = 1 AND run_condition = 'running' "
                . 'AND backup_id IS NOT NULL AND backup_digest IS NOT NULL AND lease_expires_at > UTC_TIMESTAMP(6)',
            [$id, $token, $revision, $manifestHash],
        );

        if ($owned === false) {
            throw new RuntimeException('update_switch_lease_lost');
        }
    }

    public function recordFileSwitch(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        string $manifestHash,
        int $cursor,
        bool $complete,
    ): UpdateRun {
        if ($cursor < 0) {
            throw new RuntimeException('update_switch_cursor_invalid');
        }

        $updated = $this->connection->executeStatement(
            'UPDATE update_runs SET checkpoint_cursor = ?, stage = ?, revision = revision + 1, '
                . 'updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND owner_token = ? AND revision = ? '
                . "AND stage = 'maintenance' AND active_slot = 1 AND run_condition = 'running' "
                . 'AND manifest_hash = ? AND backup_id IS NOT NULL AND backup_digest IS NOT NULL '
                . 'AND checkpoint_cursor <= ? AND lease_expires_at > UTC_TIMESTAMP(6)',
            [$complete ? 0 : $cursor, $complete ? UpdateStage::FilesSwitched->value : UpdateStage::Maintenance->value,
                $id, $token, $revision, $manifestHash, $cursor],
        );

        if ($updated !== 1) {
            throw new RuntimeException('update_switch_lease_lost');
        }

        return $this->find($id) ?? throw new RuntimeException('update_not_found');
    }

    public function assertFinalizationLease(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        string $manifestHash,
        UpdateStage $stage,
    ): void {
        if (!in_array($stage, [UpdateStage::FilesSwitched, UpdateStage::MigrationsApplied], true)) {
            throw new RuntimeException('update_finalization_stage_invalid');
        }

        $owned = $this->connection->fetchOne(
            'SELECT 1 FROM update_runs WHERE id = ? AND owner_token = ? AND revision = ? AND manifest_hash = ? '
                . "AND stage = ? AND active_slot = 1 AND run_condition = 'running' "
                . 'AND backup_id IS NOT NULL AND backup_digest IS NOT NULL AND lease_expires_at > UTC_TIMESTAMP(6)',
            [$id, $token, $revision, $manifestHash, $stage->value],
        );

        if ($owned === false) {
            throw new RuntimeException('update_finalization_lease_lost');
        }
    }

    public function recordFinalization(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        string $manifestHash,
        UpdateStage $expected,
        UpdateStage $next,
        int $cursor,
    ): UpdateRun {
        $allowed = ($expected === UpdateStage::FilesSwitched
            && in_array($next, [UpdateStage::FilesSwitched, UpdateStage::MigrationsApplied], true))
            || ($expected === UpdateStage::MigrationsApplied && $next === UpdateStage::SmokeChecked);

        if (!$allowed || $cursor < 0) {
            throw new RuntimeException('update_finalization_transition_invalid');
        }

        $changed = $this->connection->executeStatement(
            'UPDATE update_runs SET stage = ?, checkpoint_cursor = ?, revision = revision + 1, '
                . 'updated_at = UTC_TIMESTAMP(6) WHERE id = ? AND owner_token = ? AND revision = ? '
                . "AND manifest_hash = ? AND stage = ? AND active_slot = 1 AND run_condition = 'running' "
                . 'AND backup_id IS NOT NULL AND backup_digest IS NOT NULL '
                . 'AND checkpoint_cursor <= ? AND lease_expires_at > UTC_TIMESTAMP(6)',
            [
                $next->value,
                $next === $expected ? $cursor : 0,
                $id,
                $token,
                $revision,
                $manifestHash,
                $expected->value,
                $cursor,
            ],
        );

        if ($changed !== 1) {
            throw new RuntimeException('update_finalization_lease_lost');
        }

        return $this->find($id) ?? throw new RuntimeException('update_not_found');
    }

    /**
     * @param array<string, string|int|null> $changes Internal allowlisted callers only.
     */
    private function change(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        DateTimeImmutable $now,
        array $changes,
    ): UpdateRun {
        if ($revision < 0 || $revision === PHP_INT_MAX) {
            throw new RuntimeException('update_revision_invalid');
        }

        $changes['revision'] = $revision + 1;
        $changes['updated_at'] = self::stamp($now);
        $set = implode(', ', array_map(static fn(string $key): string => $key . ' = ?', array_keys($changes)));
        $updated = $this->connection->executeStatement(
            'UPDATE update_runs SET ' . $set . ' WHERE id = ? AND owner_token = ? AND revision = ? '
                . "AND active_slot = 1 AND run_condition = 'running' AND lease_expires_at > ?",
            [...array_values($changes), $id, $token, $revision, self::stamp($now)],
        );

        if ($updated !== 1) {
            throw new RuntimeException('update_lease_or_revision_lost');
        }

        return $this->find($id) ?? throw new RuntimeException('update_not_found');
    }

    private static function stamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
