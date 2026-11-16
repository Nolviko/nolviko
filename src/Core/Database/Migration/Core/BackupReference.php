<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration\Core;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

final class BackupReference implements Migration
{
    public function id(): string
    {
        return '0029_backup_reference';
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
            'ALTER TABLE update_runs ADD backup_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, '
                . 'ADD backup_digest CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL',
        ];
    }
}
