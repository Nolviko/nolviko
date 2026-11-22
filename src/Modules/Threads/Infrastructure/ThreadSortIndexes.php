<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0066 (beta.18, roadmap 14): one index per member-chosen thread list order, shaped like `ix_threads_forum_list`
 * (forum, status, sticky group, sort key, id) so every order reads a keyset page in index order (ADR-014).
 */
final class ThreadSortIndexes implements Migration
{
    public const ID = '0066_thread_sort_indexes';

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
            // CREATE INDEX (not ALTER TABLE ... ADD INDEX with commas) so an unattended update may apply it.
            'CREATE INDEX ix_threads_forum_title ON threads (forum_id, status, is_sticky, title, id)',
            'CREATE INDEX ix_threads_forum_replies ON threads (forum_id, status, is_sticky, reply_count, id)',
            'CREATE INDEX ix_threads_forum_started ON threads (forum_id, status, is_sticky, created_at, id)',
        ];
    }
}
