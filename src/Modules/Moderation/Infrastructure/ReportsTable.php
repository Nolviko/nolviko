<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0019: `reports` (M04.07a, read by the M04.07 queue).
 *
 * `open_reporter_id` equals `reporter_id` while the report is open and is NULL once it is resolved or dismissed;
 * UNIQUE (post_id, open_reporter_id) therefore allows one open report per user and post (NULLs never collide)
 * and any number of closed ones, portably. Reports go with their post; a deleted reporter becomes NULL.
 */
final class ReportsTable implements Migration
{
    public const ID = '0019_reports';

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
            'CREATE TABLE reports ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'post_id BIGINT NOT NULL, '
                . 'reporter_id BIGINT NULL, '
                . 'open_reporter_id BIGINT NULL, '
                . 'reason VARCHAR(500) NOT NULL, '
                . 'status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'resolved_by BIGINT NULL, '
                . 'resolution_note VARCHAR(500) NULL, '
                . 'resolved_at DATETIME(6) NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT uq_reports_open UNIQUE (post_id, open_reporter_id), '
                . 'INDEX ix_reports_queue (status, id), '
                . 'CONSTRAINT fk_reports_post FOREIGN KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL, '
                . 'CONSTRAINT fk_reports_open_reporter FOREIGN KEY (open_reporter_id) REFERENCES users (id) '
                . 'ON DELETE SET NULL, '
                . 'CONSTRAINT fk_reports_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
