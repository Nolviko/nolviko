<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0009: `forums` (ADR-027 adjacency list). The self-reference is ON DELETE RESTRICT, so a forum with
 * subforums cannot be deleted; the forum-scoped permission tables (0008) get their foreign keys here and lose
 * their rules with the forum (CASCADE).
 */
final class ForumsTable implements Migration
{
    public const ID = '0009_forums';

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
            'CREATE TABLE forums ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'parent_id BIGINT NULL, '
                . 'type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'title VARCHAR(200) NOT NULL, '
                . 'slug VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'sort_order INT NOT NULL DEFAULT 0, '
                . 'depth SMALLINT NOT NULL DEFAULT 0, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'updated_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT uq_forums_slug UNIQUE (slug), '
                . 'INDEX ix_forums_parent (parent_id, sort_order), '
                . 'CONSTRAINT fk_forums_parent FOREIGN KEY (parent_id) REFERENCES forums (id) ON DELETE RESTRICT'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
            'ALTER TABLE forum_permissions ADD CONSTRAINT fk_forum_permissions_forum '
                . 'FOREIGN KEY (forum_id) REFERENCES forums (id) ON DELETE CASCADE',
            'ALTER TABLE user_forum_permission_overrides ADD CONSTRAINT fk_user_forum_permission_overrides_forum '
                . 'FOREIGN KEY (forum_id) REFERENCES forums (id) ON DELETE CASCADE',
        ];
    }
}
