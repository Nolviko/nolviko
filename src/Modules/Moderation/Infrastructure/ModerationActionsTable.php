<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Reusable moderation actions (beta.18, roadmap 40): an administrator bundles steps (move, lock or unlock, pin
 * or unpin, a prefix, a reply text) that moderators apply to a thread in one click. A deleted target forum or
 * prefix only drops that step.
 */
final class ModerationActionsTable implements Migration
{
    public const ID = '0078_moderation_actions';

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
            'CREATE TABLE moderation_actions ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'title VARCHAR(100) NOT NULL, '
                . 'move_forum_id BIGINT NULL, '
                . 'lock_mode VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'\', '
                . 'sticky_mode VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT \'\', '
                . 'prefix_id BIGINT NULL, '
                . 'reply_text TEXT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'updated_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT fk_moderation_actions_forum FOREIGN KEY (move_forum_id) REFERENCES forums (id) '
                . 'ON DELETE SET NULL, '
                . 'CONSTRAINT fk_moderation_actions_prefix FOREIGN KEY (prefix_id) REFERENCES thread_prefixes (id) '
                . 'ON DELETE SET NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
