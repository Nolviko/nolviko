<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0067 (beta.18, roadmap 12): "mark all forums read" is one row per member, a board-wide read cutoff, instead of
 * one `forum_reads` row per forum. Readers fold it into the forum cutoff lookup they already make.
 */
final class MemberReadCutoffTable implements Migration
{
    public const ID = '0067_member_read_cutoffs';

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
            'CREATE TABLE member_read_cutoffs ('
                . 'user_id BIGINT NOT NULL, '
                . 'read_cutoff DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (user_id), '
                . 'CONSTRAINT fk_member_read_cutoffs_user FOREIGN KEY (user_id) REFERENCES users (id) '
                . 'ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
