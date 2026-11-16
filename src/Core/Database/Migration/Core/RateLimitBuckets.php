<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration\Core;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0018: `rate_limit_buckets` and the posting-limit exemption of moderators and administrators (M03.15).
 *
 * One row per bucket key (`post.user:<id>`, `post.net:<hash>`) with its last accepted hit; `expires_at` lets
 * old rows be pruned. The exemption is `forum.post.flood_interval_seconds` = 0 for the built-in Moderators (3)
 * and Administrators (4) groups, inserted only where no rule exists yet (0008 is applied and must not change).
 */
final class RateLimitBuckets implements Migration
{
    public const ID = '0018_rate_limit_buckets';

    private const EXEMPT_GROUPS = [3, 4];

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

        return [
            'CREATE TABLE rate_limit_buckets ('
                . 'bucket_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'last_hit_at DATETIME(6) NOT NULL, '
                . 'expires_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (bucket_key), '
                . 'INDEX ix_rate_limit_buckets_expires (expires_at)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
            'INSERT INTO usergroup_permissions (usergroup_id, permission_key, value) '
                . "SELECT g.id, 'forum.post.flood_interval_seconds', '0' FROM usergroups g "
                . 'WHERE g.id IN (' . implode(', ', self::EXEMPT_GROUPS) . ') AND NOT EXISTS ('
                . 'SELECT 1 FROM usergroup_permissions p WHERE p.usergroup_id = g.id '
                . "AND p.permission_key = 'forum.post.flood_interval_seconds')",
        ];
    }
}
