<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0014: `forums.is_locked` and `forum_statistics` (M03.04).
 *
 * One statistics row per forum, created with the forum and kept in the same transaction as each visible
 * thread or post, so forum lists never count rows. Counts cover visible content only; moderation that changes
 * visibility adjusts them (M04). The backfill makes the migration safe on a board that already has forums.
 */
final class ForumStatisticsTable implements Migration
{
    public const ID = '0014_forum_statistics';

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
            'ALTER TABLE forums ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 0 AFTER depth',
            'CREATE TABLE forum_statistics ('
                . 'forum_id BIGINT NOT NULL, '
                . 'thread_count BIGINT NOT NULL DEFAULT 0, '
                . 'post_count BIGINT NOT NULL DEFAULT 0, '
                . 'last_post_id BIGINT NULL, '
                . 'last_post_at DATETIME(6) NULL, '
                . 'updated_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (forum_id), '
                . 'CONSTRAINT fk_forum_statistics_forum FOREIGN KEY (forum_id) REFERENCES forums (id) '
                . 'ON DELETE CASCADE, '
                . 'CONSTRAINT fk_forum_statistics_last_post FOREIGN KEY (last_post_id) REFERENCES posts (id) '
                . 'ON DELETE SET NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
            'INSERT INTO forum_statistics (forum_id, thread_count, post_count, updated_at) '
                . 'SELECT f.id, '
                . "(SELECT COUNT(*) FROM threads t WHERE t.forum_id = f.id AND t.status = 'visible'), "
                . '(SELECT COUNT(*) FROM posts p JOIN threads t ON t.id = p.thread_id '
                . "WHERE t.forum_id = f.id AND t.status = 'visible' AND p.status = 'visible'), "
                . 'CURRENT_TIMESTAMP(6) FROM forums f',
        ];
    }
}
