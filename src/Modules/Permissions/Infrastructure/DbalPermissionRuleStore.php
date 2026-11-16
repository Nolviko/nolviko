<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Infrastructure;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Core\Database\SqlDialect;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Modules\Permissions\Domain\ForumModeratorCapabilities;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\PermissionRuleStore;
use Nolviko\Modules\Permissions\Domain\RuleSnapshot;

/**
 * PermissionRuleStore on DBAL. load() runs one query per table that can apply: two for a guest, four for a
 * logged-in user, independent of the number of forums and keys asked about afterwards.
 */
final class DbalPermissionRuleStore implements PermissionRuleStore
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PermissionCatalog $catalog,
    ) {
    }

    public function load(array $groupIds, ?int $userId): RuleSnapshot
    {
        $group = [];
        $groupForum = [];
        $user = [];
        $userForum = [];
        $moderatorSources = [];
        $postgres = SqlDialect::postgres($this->connection);
        $capabilityRows = MysqlModeratorProjection::rows($postgres);

        if ($groupIds !== []) {
            $parameters = [$groupIds];
            $types = [ArrayParameterType::INTEGER];
            $rows = $this->connection->fetchAllAssociative(
                'SELECT usergroup_id, permission_key, value FROM usergroup_permissions WHERE usergroup_id IN (?)',
                $parameters,
                $types,
            );

            foreach ($rows as $row) {
                $group[(string) $row['permission_key']][(int) $row['usergroup_id']] = (string) $row['value'];
            }

            $rows = $this->connection->fetchAllAssociative(
                "SELECT forum_id, usergroup_id, permission_key, value, 0 AS moderator_assignment "
                    . 'FROM forum_permissions WHERE usergroup_id IN (?) UNION ALL '
                    . 'SELECT m.forum_id, m.usergroup_id, p.permission_key, '
                    . MysqlModeratorProjection::allow($postgres)
                    . ', 1 FROM forum_moderators m '
                    . 'INNER JOIN (' . $capabilityRows . ') p ON 1 = 1 '
                    . 'WHERE m.usergroup_id IN (?) AND m.usergroup_id <> 1 AND NOT EXISTS '
                    . '(SELECT 1 FROM forum_permissions r WHERE r.forum_id = m.forum_id '
                    . 'AND r.usergroup_id = m.usergroup_id AND r.permission_key = p.permission_key)',
                [$groupIds, ...ForumModeratorCapabilities::KEYS, $groupIds],
                [ArrayParameterType::INTEGER, ...array_fill(
                    0,
                    count(ForumModeratorCapabilities::KEYS),
                    ParameterType::STRING
                ), ArrayParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $groupForum[(int) $row['forum_id']][(string) $row['permission_key']][(int) $row['usergroup_id']]
                    = (string) $row['value'];
                if ((int) $row['moderator_assignment'] === 1) {
                    $moderatorSources[(int) $row['forum_id']][(string) $row['permission_key']]
                        ['group:' . $row['usergroup_id']] = true;
                }
            }
        }

        if ($userId !== null) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT permission_key, value FROM user_permission_overrides WHERE user_id = ?',
                [$userId],
            );

            foreach ($rows as $row) {
                $user[(string) $row['permission_key']] = (string) $row['value'];
            }

            $rows = $this->connection->fetchAllAssociative(
                "SELECT forum_id, permission_key, value, 0 AS moderator_assignment "
                    . 'FROM user_forum_permission_overrides WHERE user_id = ? UNION ALL '
                    . 'SELECT m.forum_id, p.permission_key, ' . MysqlModeratorProjection::allow($postgres)
                    . ', 1 FROM forum_moderators m '
                    . 'INNER JOIN (' . $capabilityRows . ') p ON 1 = 1 '
                    . 'WHERE m.user_id = ? AND NOT EXISTS '
                    . '(SELECT 1 FROM user_forum_permission_overrides r WHERE r.forum_id = m.forum_id '
                    . 'AND r.user_id = m.user_id AND r.permission_key = p.permission_key)',
                [$userId, ...ForumModeratorCapabilities::KEYS, $userId],
                [ParameterType::INTEGER, ...array_fill(
                    0,
                    count(ForumModeratorCapabilities::KEYS),
                    ParameterType::STRING
                ), ParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $userForum[(int) $row['forum_id']][(string) $row['permission_key']] = (string) $row['value'];
                if ((int) $row['moderator_assignment'] === 1) {
                    $moderatorSources[(int) $row['forum_id']][(string) $row['permission_key']]['user'] = true;
                }
            }
        }

        return new RuleSnapshot($group, $groupForum, $user, $userForum, $moderatorSources);
    }

    public function setGroupRule(int $groupId, string $key, mixed $value, ?int $forumId = null): void
    {
        $where = ['usergroup_id' => $groupId, 'permission_key' => $key];

        if ($forumId === null) {
            $this->write('usergroup_permissions', $where, $key, $value);
        } else {
            $this->write('forum_permissions', $where + ['forum_id' => $forumId], $key, $value);
        }
    }

    public function setUserRule(int $userId, string $key, mixed $value, ?int $forumId = null): void
    {
        $where = ['user_id' => $userId, 'permission_key' => $key];

        if ($forumId === null) {
            $this->write('user_permission_overrides', $where, $key, $value);
        } else {
            $this->write('user_forum_permission_overrides', $where + ['forum_id' => $forumId], $key, $value);
        }
    }

    /**
     * Validates first, then replaces the rule (null removes it) and bumps the rules version, all in one
     * transaction (portable: delete and insert, no upsert).
     *
     * @param array<string, int|string> $where The full primary key of the row.
     */
    private function write(string $table, array $where, string $key, mixed $value): void
    {
        $definition = $this->catalog->find($key) ?? throw new PermissionError('permission_unknown:' . $key);
        $encoded = $value === null ? null : $definition->encodeRule($value);

        $replace = static function (Connection $connection) use ($table, $where, $encoded): void {
            $connection->delete($table, $where);

            if ($encoded !== null) {
                $connection->insert($table, $where + ['value' => $encoded]);
            }

            VersionCounter::bump($connection, VersionCounter::PERMISSION_RULES);
        };

        $this->connection->transactional($replace);
    }
}
