<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;

/**
 * 0008: the ADR-011 rule tables and the built-in groups' starting rules.
 *
 * Every key column is NOT NULL; `value` is the encoded rule (`1`, `0`, `never`, or the typed encoding) and an
 * absent row means inherit. `forum_id` has no foreign key yet: the `forums` table arrives in M03, whose
 * migration adds the constraints. There is no foreign key to `permissions` either, because the catalog is
 * synced after migrations and retired keys keep their rules.
 */
final class PermissionRulesTable implements Migration
{
    public const ID = '0008_permission_rules';

    /** ADR-011 global grants: group => key => encoded value. */
    public const SEED = [
        BuiltInGroup::Guests->value => [
            'forum.view' => '1',
            'forum.thread.view' => '1',
            'user.profile.view' => '1',
        ],
        BuiltInGroup::Registered->value => [
            'forum.view' => '1',
            'forum.thread.view' => '1',
            'forum.thread.create' => '1',
            'forum.post.reply' => '1',
            'forum.post.edit_own' => '1',
            'forum.post.delete_own' => '1',
            'forum.post.report' => '1',
            'forum.post.edit_window_minutes' => '60',
            'forum.post.flood_interval_seconds' => '30',
            'forum.post.approval' => 'none',
            'user.profile.view' => '1',
        ],
        BuiltInGroup::Moderators->value => [
            'moderation.post.hide' => '1',
            'moderation.post.edit_any' => '1',
            'moderation.thread.lock' => '1',
            'moderation.thread.sticky' => '1',
            'moderation.thread.move' => '1',
            'moderation.report.handle' => '1',
            'moderation.user.ban' => '1',
        ],
        BuiltInGroup::Administrators->value => [
            'admin.access' => '1',
            'moderation.post.hide' => '1',
            'moderation.post.edit_any' => '1',
            'moderation.thread.lock' => '1',
            'moderation.thread.sticky' => '1',
            'moderation.thread.move' => '1',
            'moderation.report.handle' => '1',
            'moderation.user.ban' => '1',
        ],
    ];

    private const TABLE_OPTIONS = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

    private const KEY = 'permission_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, ';

    private const VALUE = 'value VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, ';

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

        $seed = [];

        foreach (self::SEED as $groupId => $rules) {
            foreach ($rules as $key => $value) {
                $seed[] = sprintf("(%d, '%s', '%s')", $groupId, $key, $value);
            }
        }

        return [
            'CREATE TABLE usergroup_permissions ('
                . 'usergroup_id BIGINT NOT NULL, ' . self::KEY . self::VALUE
                . 'PRIMARY KEY (usergroup_id, permission_key), '
                . 'CONSTRAINT fk_usergroup_permissions_group FOREIGN KEY (usergroup_id) REFERENCES usergroups (id) '
                . 'ON DELETE CASCADE'
                . ')' . self::TABLE_OPTIONS,
            'CREATE TABLE forum_permissions ('
                . 'forum_id BIGINT NOT NULL, usergroup_id BIGINT NOT NULL, ' . self::KEY . self::VALUE
                . 'PRIMARY KEY (forum_id, usergroup_id, permission_key), '
                . 'INDEX ix_forum_permissions_group (usergroup_id), '
                . 'CONSTRAINT fk_forum_permissions_group FOREIGN KEY (usergroup_id) REFERENCES usergroups (id) '
                . 'ON DELETE CASCADE'
                . ')' . self::TABLE_OPTIONS,
            'CREATE TABLE user_permission_overrides ('
                . 'user_id BIGINT NOT NULL, ' . self::KEY . self::VALUE
                . 'PRIMARY KEY (user_id, permission_key), '
                . 'CONSTRAINT fk_user_permission_overrides_user FOREIGN KEY (user_id) REFERENCES users (id) '
                . 'ON DELETE CASCADE'
                . ')' . self::TABLE_OPTIONS,
            'CREATE TABLE user_forum_permission_overrides ('
                . 'user_id BIGINT NOT NULL, forum_id BIGINT NOT NULL, ' . self::KEY . self::VALUE
                . 'PRIMARY KEY (user_id, forum_id, permission_key), '
                . 'CONSTRAINT fk_user_forum_permission_overrides_user FOREIGN KEY (user_id) REFERENCES users (id) '
                . 'ON DELETE CASCADE'
                . ')' . self::TABLE_OPTIONS,
            'INSERT INTO usergroup_permissions (usergroup_id, permission_key, value) VALUES ' . implode(', ', $seed),
        ];
    }
}
