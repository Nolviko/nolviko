<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Thread prefixes and tags (2026-11-08, ThreadTaxonomy). A prefix belongs to one forum or, with `forum_id` NULL,
 * to every forum; a thread has at most one. Tags are board-wide; a thread has up to ThreadTaxonomy::MAX_TAGS.
 */
final class ThreadTaxonomyTables implements Migration
{
    public function id(): string
    {
        return '0046_thread_taxonomy';
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
            'CREATE TABLE thread_prefixes (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, forum_id BIGINT NULL, '
                . 'label VARCHAR(40) NOT NULL, color VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, INDEX ix_thread_prefixes_forum (forum_id, id), '
                . 'CONSTRAINT fk_thread_prefixes_forum FOREIGN KEY (forum_id) REFERENCES forums (id) '
                . 'ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            // 2026-11-16: one clause per ALTER TABLE, as unattended updates require (ExpandOnlyPolicy).
            'ALTER TABLE threads ADD COLUMN prefix_id BIGINT NULL',
            'ALTER TABLE threads ADD CONSTRAINT fk_threads_prefix FOREIGN KEY (prefix_id) '
                . 'REFERENCES thread_prefixes (id) ON DELETE SET NULL',
            'CREATE TABLE tags (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(40) NOT NULL, '
                . 'slug VARCHAR(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, created_at DATETIME(6) NOT NULL, '
                . 'UNIQUE KEY uq_tags_slug (slug)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE thread_tags (thread_id BIGINT NOT NULL, tag_id BIGINT NOT NULL, '
                . 'PRIMARY KEY (thread_id, tag_id), INDEX ix_thread_tags_tag (tag_id, thread_id), '
                . 'CONSTRAINT fk_thread_tags_thread FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_thread_tags_tag FOREIGN KEY (tag_id) REFERENCES tags (id) ON DELETE CASCADE) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
