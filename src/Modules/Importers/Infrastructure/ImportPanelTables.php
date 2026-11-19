<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Imports from the admin panel (beta.11): sources entered in the panel (the password sealed with the app secret),
 * per-entity counters kept apart from the job's checkpoint so a growing report never blocks a resume, and the
 * owner's "publish" decision on the job.
 */
final class ImportPanelTables implements Migration
{
    public function id(): string
    {
        return '0054_import_panel';
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
        $options = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        return [
            'CREATE TABLE import_sources (id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, '
                . 'engine VARCHAR(12) NOT NULL, host VARCHAR(255) NOT NULL, port INT NOT NULL, '
                . 'database_name VARCHAR(64) NOT NULL, username VARCHAR(128) NOT NULL, '
                . 'password_sealed TEXT NOT NULL, prefix VARCHAR(48) NOT NULL, charset VARCHAR(16) NOT NULL, '
                . 'attachment_root VARCHAR(1024) NULL, preflight_json TEXT NULL, created_by BIGINT NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL)' . $options,
            'CREATE TABLE import_counts (job_id CHAR(32) NOT NULL, entity VARCHAR(24) NOT NULL, '
                . 'processed BIGINT NOT NULL DEFAULT 0, verified BIGINT NOT NULL DEFAULT 0, '
                . 'PRIMARY KEY (job_id, entity), '
                . 'CONSTRAINT fk_import_counts_job FOREIGN KEY (job_id) REFERENCES import_jobs(id))' . $options,
            'ALTER TABLE import_jobs ADD COLUMN published_at DATETIME(6) NULL',
        ];
    }
}
