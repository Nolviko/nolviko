<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration\Core;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0001: the runner's own tables (`migration_history`, `migration_leases`) and `settings`.
 *
 * The first BOOTSTRAP_STEPS statements are `CREATE TABLE IF NOT EXISTS`, so the runner can create its tables
 * before it holds a lease (there is nowhere to record a lease yet) and still record them as this migration.
 */
final class CoreFoundation implements Migration
{
    public const ID = '0001_core_foundation';

    /** Leading statements that are safe to run before the lease and again as steps. */
    public const BOOTSTRAP_STEPS = 2;

    private const MYSQL_TABLE = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';

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
            'CREATE TABLE IF NOT EXISTS migration_history ('
                . 'id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'phase VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'completed_steps INT NOT NULL DEFAULT 0, '
                . 'started_at DATETIME(6) NOT NULL, '
                . 'applied_at DATETIME(6) NULL, '
                . 'PRIMARY KEY (id))' . self::MYSQL_TABLE,
            'CREATE TABLE IF NOT EXISTS migration_leases ('
                . 'lease_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'owner_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'expires_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (lease_name))' . self::MYSQL_TABLE,
            'CREATE TABLE IF NOT EXISTS settings ('
                . 'setting_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'setting_value MEDIUMTEXT NOT NULL, '
                . 'value_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'updated_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (setting_key))' . self::MYSQL_TABLE,
        ];
    }
}
