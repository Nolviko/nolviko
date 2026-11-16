<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration\Core;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0028: durable update progress and a unique active slot; cache clearing cannot lose an unfinished update.
 */
final class UpdateRuns implements Migration
{
    public function id(): string
    {
        return '0028_update_runs';
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
            'CREATE TABLE update_runs ('
                . 'id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'active_slot SMALLINT NULL, '
                . 'target VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'manifest_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'stage VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'checkpoint_cursor BIGINT NOT NULL DEFAULT 0, '
                . 'revision BIGINT NOT NULL DEFAULT 0, '
                . 'run_condition VARCHAR(24) NOT NULL, '
                . 'diagnostic VARCHAR(80) NULL, '
                . 'owner_token CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'lease_expires_at DATETIME(6) NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'updated_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT uq_update_active UNIQUE (active_slot)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
