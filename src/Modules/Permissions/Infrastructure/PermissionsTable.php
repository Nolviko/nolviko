<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0006: `permissions`, the database copy of PermissionCatalog (filled by PermissionCatalogSync, not here, so
 * that adding a permission never changes this migration's checksum). Keys dropped from the code are kept with
 * `retired_at` set, because rules stored for them (M02.09) must stay readable until cleaned up.
 */
final class PermissionsTable implements Migration
{
    public const ID = '0006_permissions';

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
            'CREATE TABLE permissions ('
                . 'permission_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'value_type VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'options VARCHAR(1000) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'min_value BIGINT NOT NULL, '
                . 'max_value BIGINT NOT NULL, '
                . 'synced_at DATETIME(6) NOT NULL, '
                . 'retired_at DATETIME(6) NULL, '
                . 'PRIMARY KEY (permission_key)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
