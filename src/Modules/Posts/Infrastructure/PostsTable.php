<?php

declare(strict_types=1);

namespace Nolviko\Modules\Posts\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0011: `posts` and `post_revisions` (DATABASE.md), and the thread → first/last post references.
 *
 * - `content` is the logical LONG_TEXT (MEDIUMTEXT, 16 MiB; the application enforces a lower limit, M03.04).
 * - `content_format` names the source format (decided by ADR-012) and `renderer_version` the renderer that
 *   produced the cached output; NULL means "render again".
 * - Delete behaviour: a deleted thread takes its posts with it and a deleted post its revisions (CASCADE).
 *   Normal moderation hides posts through `status` instead. A deleted account leaves its posts and edits with
 *   no author (SET NULL). Thread first/last post references become NULL if that post is removed.
 */
final class PostsTable implements Migration
{
    public const ID = '0011_posts';

    public const THREAD_INDEX = 'ix_posts_thread';

    public const USER_INDEX = 'ix_posts_user';

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

        return [
            'CREATE TABLE posts ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'thread_id BIGINT NOT NULL, '
                . 'user_id BIGINT NULL, '
                . 'content MEDIUMTEXT NOT NULL, '
                . 'content_format VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'renderer_version INT NULL, '
                . 'status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'edited_at DATETIME(6) NULL, '
                . 'edited_by BIGINT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'updated_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'INDEX ' . self::THREAD_INDEX . ' (thread_id, status, id), '
                . 'INDEX ' . self::USER_INDEX . ' (user_id, status, created_at DESC, id DESC), '
                . 'CONSTRAINT fk_posts_thread FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_posts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL, '
                . 'CONSTRAINT fk_posts_edited_by FOREIGN KEY (edited_by) REFERENCES users (id) ON DELETE SET NULL'
                . ')' . self::TABLE_OPTIONS,
            'CREATE TABLE post_revisions ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'post_id BIGINT NOT NULL, '
                . 'editor_id BIGINT NULL, '
                . 'previous_content MEDIUMTEXT NOT NULL, '
                . 'previous_format VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'change_reason VARCHAR(255) NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'INDEX ix_post_revisions_post (post_id, id), '
                . 'CONSTRAINT fk_post_revisions_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_post_revisions_editor FOREIGN KEY (editor_id) REFERENCES users (id) '
                . 'ON DELETE SET NULL'
                . ')' . self::TABLE_OPTIONS,
            'ALTER TABLE threads '
                . 'ADD CONSTRAINT fk_threads_first_post FOREIGN KEY (first_post_id) REFERENCES posts (id) '
                . 'ON DELETE SET NULL, '
                . 'ADD CONSTRAINT fk_threads_last_post FOREIGN KEY (last_post_id) REFERENCES posts (id) '
                . 'ON DELETE SET NULL',
        ];
    }
}
