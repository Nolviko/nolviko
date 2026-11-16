<?php

declare(strict_types=1);

namespace Nolviko\Modules\Groups\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;

/**
 * 0005: `usergroups` with the four built-in groups (stable IDs 1–4, custom IDs from 100) and
 * `usergroup_members`. Memberships go with their user or group (ON DELETE CASCADE); `primary_user_id` is a
 * VIRTUAL generated column (MySQL forbids CASCADE on base columns of STORED ones) that is the user ID only on
 * the primary row, so its UNIQUE constraint allows at most one primary group per user.
 */
final class UsergroupsTable implements Migration
{
    public const ID = '0005_usergroups';

    private const TABLE_OPTIONS = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    public function id(): string
    {
        return self::ID;
    }

    public function phase(): MigrationPhase
    {
        return MigrationPhase::Expand;
    }

    public function statements(string $backend): array
    {
        if ($backend !== 'mysql') {
            throw new MigrationException('migration_backend_unsupported:' . $backend);
        }

        $builtIns = array_map(
            static fn(BuiltInGroup $group): string => sprintf(
                "(%d, '%s', '%s', 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                $group->value,
                $group->slug(),
                $group->name,
            ),
            BuiltInGroup::cases(),
        );

        return [
            'CREATE TABLE usergroups ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'slug VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'name VARCHAR(100) NOT NULL, '
                . 'built_in TINYINT(1) NOT NULL DEFAULT 0, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'updated_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT uq_usergroups_slug UNIQUE (slug)'
                . ')' . self::TABLE_OPTIONS . ' AUTO_INCREMENT=' . BuiltInGroup::FIRST_CUSTOM_ID,
            'INSERT INTO usergroups (id, slug, name, built_in, created_at, updated_at) VALUES '
                . implode(', ', $builtIns),
            'CREATE TABLE usergroup_members ('
                . 'user_id BIGINT NOT NULL, '
                . 'usergroup_id BIGINT NOT NULL, '
                . 'is_primary TINYINT(1) NOT NULL DEFAULT 0, '
                . 'primary_user_id BIGINT GENERATED ALWAYS AS (IF(is_primary = 1, user_id, NULL)) VIRTUAL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (user_id, usergroup_id), '
                . 'INDEX ix_usergroup_members_group (usergroup_id), '
                . 'CONSTRAINT uq_usergroup_members_primary UNIQUE (primary_user_id), '
                . 'CONSTRAINT fk_usergroup_members_user FOREIGN KEY (user_id) REFERENCES users (id) '
                . 'ON DELETE CASCADE, '
                . 'CONSTRAINT fk_usergroup_members_group FOREIGN KEY (usergroup_id) REFERENCES usergroups (id) '
                . 'ON DELETE CASCADE'
                . ')' . self::TABLE_OPTIONS,
        ];
    }
}
