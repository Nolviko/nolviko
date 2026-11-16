<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\Connection;

/** Picks the update backup for the connection's backend (2026-11-14). */
final class DatabaseBackups
{
    public static function for(Connection $connection): DatabaseBackup
    {
        return SqlDialect::postgres($connection) ? new PostgresBackup($connection) : new MySqlBackup($connection);
    }
}
