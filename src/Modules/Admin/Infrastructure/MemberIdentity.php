<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;
use Nolviko\Modules\Users\Domain\IdentityRules;

/**
 * Administrator edits of a member's username and email (V1 gap list). The same rules as registration apply
 * (IdentityRules, normalized unique columns). Every change needs a fresh administrator session and runs in one
 * transaction with an `audit_logs` row; an email change also voids the member's pending email-change links.
 * Content references members by id, so a rename needs no further rewrites.
 *
 * Another administrator's account is protected (security review 2026-11-08): changing their email and then sending
 * a password reset would let one administrator take over another. Administrators change their own identity here.
 */
final readonly class MemberIdentity
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Refuses changes to another administrator's account (`member_admin_protected`).
     *
     * @throws PermissionError Without a fresh administrator session.
     */
    public function assertEditable(SubjectPermissions $subject, bool $fresh, int $userId): void
    {
        $actor = self::actor($subject, $fresh);

        if ($actor !== $userId && $this->isAdministrator($userId)) {
            throw new InvalidArgumentException('member_admin_protected');
        }
    }

    public function isAdministrator(int $userId): bool
    {
        return $this->db->fetchOne(
            'SELECT 1 FROM usergroup_members WHERE user_id = ? AND usergroup_id = ?',
            [$userId, BuiltInGroup::Administrators->value],
        ) !== false;
    }

    /** @return bool False when the username was already that value. */
    public function rename(SubjectPermissions $subject, bool $fresh, int $userId, string $username): bool
    {
        $this->assertEditable($subject, $fresh, $userId);
        $actor = self::actor($subject, $fresh);
        $clean = IdentityRules::username($username);

        if ($clean === null) {
            throw new InvalidArgumentException('member_username_invalid');
        }

        return $this->change($actor, $userId, 'username', $clean, IdentityNormalizer::username($clean));
    }

    /** @return bool False when the email was already that value. */
    public function changeEmail(SubjectPermissions $subject, bool $fresh, int $userId, string $email): bool
    {
        $this->assertEditable($subject, $fresh, $userId);
        $actor = self::actor($subject, $fresh);
        $clean = IdentityRules::email($email);

        if ($clean === null) {
            throw new InvalidArgumentException('member_email_invalid');
        }

        return $this->change($actor, $userId, 'email', $clean, IdentityNormalizer::email($clean));
    }

    private function change(int $actor, int $userId, string $field, string $value, string $normalized): bool
    {
        try {
            return $this->db->transactional(function (Connection $db) use (
                $actor,
                $userId,
                $field,
                $value,
                $normalized,
            ): bool {
                $current = $db->fetchOne(
                    $field === 'email' ? 'SELECT email FROM users WHERE id = ? FOR UPDATE'
                        : 'SELECT username FROM users WHERE id = ? FOR UPDATE',
                    [$userId],
                );

                if ($current === false) {
                    throw new InvalidArgumentException('group_member_unknown');
                }
                if ((string) $current === $value) {
                    return false;
                }

                $now = (string) $db->fetchOne('SELECT UTC_TIMESTAMP(6)');
                $db->executeStatement(
                    $field === 'email'
                        ? 'UPDATE users SET email = ?, email_normalized = ?, updated_at = ? WHERE id = ?'
                        : 'UPDATE users SET username = ?, username_normalized = ?, updated_at = ? WHERE id = ?',
                    [$value, $normalized, $now, $userId],
                );

                if ($field === 'email') {
                    $db->executeStatement(
                        'UPDATE email_change_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL',
                        [$now, $userId],
                    );
                }

                $db->insert('audit_logs', ['actor_id' => $actor, 'action' => 'member.' . $field . '_changed',
                    'target_type' => 'user', 'target_id' => $userId, 'reason' => null,
                    'details' => json_encode(['from' => (string) $current, 'to' => $value], JSON_THROW_ON_ERROR),
                    'created_at' => $now]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('member_' . $field . '_taken');
        }
    }

    private static function actor(SubjectPermissions $subject, bool $fresh): int
    {
        if (!$fresh || $subject->userId === null || !$subject->allows('admin.access')) {
            throw new PermissionError('group_admin_denied');
        }

        return $subject->userId;
    }
}
