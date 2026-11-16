<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0010: `threads`. `forum_id` is ON DELETE RESTRICT, so a forum that still has threads cannot be deleted
 * (M03.01). Authors may be deleted (SET NULL; the name is then shown as a removed account). `last_poster_id`
 * is denormalized next to `last_post_at` so thread lists join users directly and need no posts lookup.
 *
 * Indexes follow the list queries exactly: `ix_threads_forum_list` (forum page: sticky first, then latest
 * activity) and `ix_threads_latest` (latest threads across forums).
 */
final class ThreadsTable implements Migration
{
    public const ID = '0010_threads';

    public const FORUM_LIST_INDEX = 'ix_threads_forum_list';

    public const LATEST_INDEX = 'ix_threads_latest';

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
            'CREATE TABLE threads ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'forum_id BIGINT NOT NULL, '
                . 'user_id BIGINT NULL, '
                . 'title VARCHAR(255) NOT NULL, '
                . 'slug VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'is_locked TINYINT(1) NOT NULL DEFAULT 0, '
                . 'is_sticky TINYINT(1) NOT NULL DEFAULT 0, '
                . 'first_post_id BIGINT NULL, '
                . 'last_post_id BIGINT NULL, '
                . 'last_post_at DATETIME(6) NULL, '
                . 'last_poster_id BIGINT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'updated_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'INDEX ' . self::FORUM_LIST_INDEX
                . ' (forum_id, status, is_sticky DESC, last_post_at DESC, id DESC), '
                . 'INDEX ' . self::LATEST_INDEX . ' (status, last_post_at DESC, id DESC), '
                . 'INDEX ix_threads_user (user_id), '
                . 'CONSTRAINT fk_threads_forum FOREIGN KEY (forum_id) REFERENCES forums (id) ON DELETE RESTRICT, '
                . 'CONSTRAINT fk_threads_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL, '
                . 'CONSTRAINT fk_threads_last_poster FOREIGN KEY (last_poster_id) REFERENCES users (id) '
                . 'ON DELETE SET NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
