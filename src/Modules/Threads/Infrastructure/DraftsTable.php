<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0027: `drafts` (M03.14): one unsent text per member and place (`thread:<forum>` for a new thread,
 * `reply:<thread>` for a reply), replaced on every save and removed when the post is sent.
 */
final class DraftsTable implements Migration
{
    public const ID = '0027_drafts';

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
            'CREATE TABLE drafts ('
                . 'user_id BIGINT NOT NULL, '
                . 'context VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'title VARCHAR(255) NULL, '
                . 'content MEDIUMTEXT NOT NULL, '
                . 'updated_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (user_id, context), '
                . 'CONSTRAINT fk_drafts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
