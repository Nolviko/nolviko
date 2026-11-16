<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

final class ForumModeratorsTable implements Migration
{
    public function id(): string
    {
        return '0037_forum_moderators';
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

        return ['CREATE TABLE forum_moderators ('
            . 'id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, forum_id BIGINT NOT NULL, '
            . 'user_id BIGINT NULL, usergroup_id BIGINT NULL, created_at DATETIME(6) NOT NULL, '
            . 'CONSTRAINT ck_forum_moderator_target CHECK '
            . '((user_id IS NOT NULL AND usergroup_id IS NULL) OR (user_id IS NULL AND usergroup_id IS NOT NULL)), '
            . 'CONSTRAINT ck_forum_moderator_guest CHECK (usergroup_id IS NULL OR usergroup_id <> 1), '
            . 'CONSTRAINT uq_forum_moderator_user UNIQUE (forum_id, user_id), '
            . 'CONSTRAINT uq_forum_moderator_group UNIQUE (forum_id, usergroup_id), '
            . 'INDEX ix_forum_moderator_user (user_id, forum_id), '
            . 'INDEX ix_forum_moderator_group (usergroup_id, forum_id), '
            . 'CONSTRAINT fk_forum_moderator_forum FOREIGN KEY (forum_id) REFERENCES forums(id) ON DELETE CASCADE, '
            . 'CONSTRAINT fk_forum_moderator_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE, '
            . 'CONSTRAINT fk_forum_moderator_group FOREIGN KEY (usergroup_id) REFERENCES usergroups(id) '
            . 'ON DELETE CASCADE) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'];
    }
}
