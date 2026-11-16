<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0026: sparse read tracking (DATABASE.md, M03.12). `thread_reads` holds the last read post per member and thread
 * only where it matters; `forum_reads` holds a "marked as read" time per member and forum, which replaces that
 * forum's thread rows. Threads without a post in the read window count as read and need no row at all.
 */
final class ReadTrackingTable implements Migration
{
    public const ID = '0026_read_tracking';

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

        $options = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

        return [
            'CREATE TABLE thread_reads ('
                . 'user_id BIGINT NOT NULL, '
                . 'thread_id BIGINT NOT NULL, '
                . 'last_read_post_id BIGINT NOT NULL, '
                . 'read_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (user_id, thread_id), '
                . 'INDEX ix_thread_reads_thread (thread_id), '
                . 'CONSTRAINT fk_thread_reads_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_thread_reads_thread FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE'
                . ')' . $options,
            'CREATE TABLE forum_reads ('
                . 'user_id BIGINT NOT NULL, '
                . 'forum_id BIGINT NOT NULL, '
                . 'read_cutoff DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (user_id, forum_id), '
                . 'INDEX ix_forum_reads_forum (forum_id), '
                . 'CONSTRAINT fk_forum_reads_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_forum_reads_forum FOREIGN KEY (forum_id) REFERENCES forums (id) ON DELETE CASCADE'
                . ')' . $options,
        ];
    }
}
