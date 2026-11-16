<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;

/**
 * The few runtime SQL forms that differ between the MySQL family and PostgreSQL (2026-11-14): insert-or-skip,
 * insert-or-update and the shared row lock. Everything else in the repositories is written in the common
 * subset (CASE instead of IF, `INTERVAL '5' MINUTE` literals, `utc_timestamp(6)` which the PostgreSQL schema
 * provides). Not a SQL translator: callers pass the plain parts and get one statement back.
 */
final class SqlDialect
{
    public static function postgres(Connection $connection): bool
    {
        return $connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    /** The runtime-policy driver name of the connection's server: `mysql`, `mariadb` or `postgresql`. */
    public static function driver(Connection $connection): string
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof PostgreSQLPlatform => 'postgresql',
            $platform instanceof MariaDBPlatform => 'mariadb',
            default => 'mysql',
        };
    }

    /**
     * `INSERT INTO ...` that silently skips rows hitting a unique key (MySQL `INSERT IGNORE`, PostgreSQL
     * `ON CONFLICT DO NOTHING`). Unlike INSERT IGNORE it does not hide other errors on PostgreSQL.
     */
    public static function insertIgnore(Connection $connection, string $insert): string
    {
        if (!str_starts_with($insert, 'INSERT INTO ')) {
            throw new InvalidArgumentException('sql_insert_expected');
        }

        return self::postgres($connection)
            ? $insert . ' ON CONFLICT DO NOTHING'
            : 'INSERT IGNORE INTO ' . substr($insert, strlen('INSERT INTO '));
    }

    /**
     * `INSERT INTO ...` followed by an update of the existing row on a key conflict. `$assignments` uses
     * `@new.column` for the proposed value and `@old.column` for the stored one; they become `VALUES(column)` /
     * `column` on MySQL and `EXCLUDED.column` / `table.column` on PostgreSQL.
     *
     * @param list<string> $key The conflicting unique key's columns (PostgreSQL names it).
     */
    public static function upsert(
        Connection $connection,
        string $insert,
        string $table,
        array $key,
        string $assignments,
    ): string {
        if (self::postgres($connection)) {
            $set = (string) preg_replace(
                ['/@new\.(\w+)/', '/@old\.(\w+)/'],
                ['EXCLUDED.$1', $table . '.$1'],
                $assignments,
            );

            return $insert . ' ON CONFLICT (' . implode(', ', $key) . ') DO UPDATE SET ' . $set;
        }

        $set = (string) preg_replace(['/@new\.(\w+)/', '/@old\.(\w+)/'], ['VALUES($1)', '$1'], $assignments);

        return $insert . ' ON DUPLICATE KEY UPDATE ' . $set;
    }

    /** Shared row lock suffix: MariaDB has no FOR SHARE, PostgreSQL no LOCK IN SHARE MODE. */
    public static function shareLock(Connection $connection): string
    {
        return self::postgres($connection) ? 'FOR SHARE' : 'LOCK IN SHARE MODE';
    }
}
