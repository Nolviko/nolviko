<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Nolviko\Modules\Admin\Domain\AdminFilterSchema;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;

/** Private administrator preferences, capped and serialized under the owner's user-row lock. */
final readonly class SavedAdminFilters
{
    public const LIMIT = 30;

    public function __construct(private Connection $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(SubjectPermissions $subject): array
    {
        $actor = self::actor($subject);

        return $this->db->fetchAllAssociative(
            'SELECT id, name, context, filters_json FROM admin_saved_filters '
                . 'WHERE user_id = ? ORDER BY id DESC LIMIT 30',
            [$actor],
        );
    }

    /** @param array<string, mixed> $filters */
    public function save(SubjectPermissions $subject, string $name, string $context, array $filters): int
    {
        $actor = self::actor($subject);
        $name = trim($name);

        if (
            $name === '' || strlen($name) > 320 || !mb_check_encoding($name, 'UTF-8')
            || mb_strlen($name) > 80 || grapheme_strlen($name) > 80 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1
        ) {
            throw new InvalidArgumentException('admin_filter_name_invalid');
        }

        $clean = AdminFilterSchema::clean($context, $filters);
        $hash = hash('sha256', IdentityNormalizer::username($name));

        return $this->db->transactional(function () use ($actor, $name, $context, $clean, $hash): int {
            if ($this->db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$actor]) === false) {
                throw new PermissionError('admin_filter_denied');
            }

            $known = $this->db->fetchOne(
                'SELECT id FROM admin_saved_filters WHERE user_id = ? AND name_hash = ?',
                [$actor, $hash],
            );

            if ($known !== false) {
                throw new InvalidArgumentException('admin_filter_name_taken');
            }

            if (
                (int) $this->db->fetchOne(
                    'SELECT COUNT(*) FROM admin_saved_filters WHERE user_id = ?',
                    [$actor],
                ) >= self::LIMIT
            ) {
                throw new InvalidArgumentException('admin_filter_limit');
            }

            $this->db->insert('admin_saved_filters', ['user_id' => $actor, 'name' => $name, 'name_hash' => $hash,
                'context' => $context, 'filters_json' => json_encode($clean, JSON_THROW_ON_ERROR),
                'created_at' => $this->now(), 'updated_at' => $this->now()]);
            $id = (int) $this->db->lastInsertId();
            $this->audit($actor, $id, 'admin.saved_filter_created');

            return $id;
        });
    }

    public function remove(SubjectPermissions $subject, int $id): bool
    {
        $actor = self::actor($subject);

        return $this->db->transactional(function () use ($actor, $id): bool {
            $changed = $this->db->delete('admin_saved_filters', ['user_id' => $actor, 'id' => $id]);

            if ($changed > 0) {
                $this->audit($actor, $id, 'admin.saved_filter_deleted');
            }

            return $changed > 0;
        });
    }

    private static function actor(SubjectPermissions $subject): int
    {
        if ($subject->userId === null || !$subject->allows('admin.access')) {
            throw new PermissionError('admin_filter_denied');
        }

        return $subject->userId;
    }

    private function audit(int $actor, int $id, string $action): void
    {
        $this->db->insert('audit_logs', ['actor_id' => $actor, 'action' => $action,
            'target_type' => 'saved_filter', 'target_id' => $id, 'reason' => null, 'details' => null,
            'created_at' => $this->now()]);
    }

    private function now(): string
    {
        return (string) $this->db->fetchOne('SELECT UTC_TIMESTAMP(6)');
    }
}
