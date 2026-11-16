<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration\Core;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

final class InstalledRelease implements Migration
{
    public function id(): string
    {
        return '0030_installed_release';
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
            'CREATE TABLE installed_release (id SMALLINT NOT NULL PRIMARY KEY, release_sequence BIGINT NOT NULL, '
                . 'release_id VARCHAR(128) NOT NULL, manifest_hash CHAR(64) NOT NULL, job_id CHAR(32) NOT NULL) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
