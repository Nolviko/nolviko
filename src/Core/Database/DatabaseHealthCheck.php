<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception as DbalException;

/**
 * Connects through DBAL and verifies the selected backend is one the forum supports (installer, M01.13/14).
 *
 * Beta.10 (owner plan 2026-11-18, "the database the host gives"): MySQL 8.0 or newer (8.x and 9.x), MariaDB 10.6
 * or newer, PostgreSQL 14 or newer. The version is detected; a branch that runs is not a claim that its vendor
 * still ships security fixes (docs/compatibility.md keeps that apart). The driver must match the server family
 * (`database_family_invalid:mariadb|mysql`). PostgreSQL: UTF-8 server and client encoding and the UTC session
 * time zone (PostgresSession).
 */
final class DatabaseHealthCheck
{
    /** The lowest MySQL release accepted, and the first major that is not (beta.10). */
    public const MYSQL_MINIMUM = '8.0.0';

    public const MYSQL_BELOW = '10.0.0';

    /** The lowest PostgreSQL release accepted (`server_version_num`). */
    public const POSTGRES_MINIMUM = 140000;

    private const POSTGRES_FACTS = "SELECT current_setting('server_version') AS version, "
        . "current_setting('server_version_num') AS version_num, current_setting('server_encoding') AS "
        . "server_encoding, current_setting('client_encoding') AS client_encoding, "
        . "current_setting('TimeZone') AS time_zone";

    /** PostgreSQL SQLSTATE => diagnostic ID. */
    private const POSTGRES_ERRORS = [
        '28P01' => 'database_auth_failed',
        '28000' => 'database_auth_failed',
        '3D000' => 'database_not_found',
        '08001' => 'database_unreachable',
        '08006' => 'database_unreachable',
    ];

    /** The lowest MariaDB release accepted (10.6 LTS, beta.10). */
    public const MARIADB_MINIMUM = '10.6.0';

    private const MYSQL_FACTS = 'SELECT VERSION() AS version, @@character_set_client AS charset_client, '
        . '@@character_set_connection AS charset_connection, @@character_set_results AS charset_results, '
        . '@@session.time_zone AS time_zone, @@session.sql_mode AS sql_mode';

    /** MySQL client/server error codes => diagnostic ID; anything else is `database_connection_failed`. */
    private const CONNECT_ERRORS = [
        1045 => 'database_auth_failed',
        1524 => 'database_auth_failed', // MySQL 8.4 reports an unknown user this way (DBAL works around it too).
        1698 => 'database_auth_failed',
        // Logged in, but the database is missing or not granted: MySQL does not tell the two apart (1044).
        1044 => 'database_not_found',
        1049 => 'database_not_found',
        2002 => 'database_unreachable',
        2003 => 'database_unreachable',
        2005 => 'database_unreachable',
        2006 => 'database_unreachable',
        2013 => 'database_unreachable',
    ];

    /**
     * @return array<string, string> The verified server facts (version, charsets, time zone, SQL mode).
     *
     * @throws DatabaseHealthException With stable diagnostic IDs and no credentials.
     */
    public static function check(Connection $connection, string $driver): array
    {
        if ($driver === 'postgresql') {
            return self::postgres($connection);
        }

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            throw new DatabaseHealthException(['database_backend_unverified:' . $driver]);
        }

        try {
            $facts = $connection->fetchAssociative(self::MYSQL_FACTS);
        } catch (DbalException $failure) {
            throw new DatabaseHealthException([self::connectError($failure)]);
        }

        if ($facts === false) {
            throw new DatabaseHealthException(['database_connection_failed']);
        }

        $facts = array_map(strval(...), $facts);
        $diagnostics = self::mysqlDiagnostics($facts, $driver);

        if ($diagnostics !== []) {
            throw new DatabaseHealthException($diagnostics);
        }

        return $facts;
    }

    /**
     * @param array<string, string> $facts Values from MYSQL_FACTS.
     * @param string $driver `mysql` or `mariadb`; the server must be of that family.
     *
     * @return list<string>
     */
    public static function mysqlDiagnostics(array $facts, string $driver = 'mysql'): array
    {
        $version = $facts['version'] ?? '';
        $mariadb = stripos($version, 'mariadb') !== false;

        if ($mariadb !== ($driver === 'mariadb')) {
            return ['database_family_invalid:' . ($mariadb ? 'mariadb' : 'mysql')];
        }

        $diagnostics = [];
        // Older MariaDB servers report `5.5.5-10.11.6-MariaDB` for client compatibility.
        $number = self::versionNumber($mariadb ? (string) preg_replace('/^5\.5\.5-/', '', $version) : $version);
        $supported = $mariadb
            ? $number !== 'unknown' && version_compare($number, self::MARIADB_MINIMUM, '>=')
            : $number !== 'unknown' && version_compare($number, self::MYSQL_MINIMUM, '>=')
                && version_compare($number, self::MYSQL_BELOW, '<');

        if (!$supported) {
            $diagnostics[] = 'database_version_unsupported:' . $number;
        }

        foreach (['charset_client', 'charset_connection', 'charset_results'] as $charset) {
            if (($facts[$charset] ?? '') !== MysqlSession::CHARSET) {
                $diagnostics[] = 'database_charset_invalid';

                break;
            }
        }

        if (($facts['time_zone'] ?? '') !== MysqlSession::TIME_ZONE) {
            $diagnostics[] = 'database_timezone_invalid';
        }

        $modes = explode(',', strtoupper($facts['sql_mode'] ?? ''));

        if (array_diff(MysqlSession::SQL_MODES, $modes) !== []) {
            $diagnostics[] = 'database_sql_mode_invalid';
        }

        return $diagnostics;
    }

    /**
     * @param array<string, string> $facts Values from POSTGRES_FACTS.
     *
     * @return list<string>
     */
    public static function postgresDiagnostics(array $facts): array
    {
        $diagnostics = [];

        if ((int) ($facts['version_num'] ?? 0) < self::POSTGRES_MINIMUM) {
            $diagnostics[] = 'database_version_unsupported:' . self::versionNumber($facts['version'] ?? '');
        }

        if (
            strtoupper($facts['server_encoding'] ?? '') !== 'UTF8'
            || strtoupper($facts['client_encoding'] ?? '') !== 'UTF8'
        ) {
            $diagnostics[] = 'database_charset_invalid';
        }

        if (!in_array($facts['time_zone'] ?? '', [PostgresSession::TIME_ZONE, 'Etc/UTC'], true)) {
            $diagnostics[] = 'database_timezone_invalid';
        }

        return $diagnostics;
    }

    /**
     * @return array<string, string>
     *
     * @throws DatabaseHealthException
     */
    private static function postgres(Connection $connection): array
    {
        try {
            $facts = $connection->fetchAssociative(self::POSTGRES_FACTS);
        } catch (DbalException $failure) {
            throw new DatabaseHealthException([self::postgresConnectError($failure)]);
        }

        if ($facts === false) {
            throw new DatabaseHealthException(['database_connection_failed']);
        }

        $facts = array_map(strval(...), $facts);
        $diagnostics = self::postgresDiagnostics($facts);

        if ($diagnostics !== []) {
            throw new DatabaseHealthException($diagnostics);
        }

        return $facts;
    }

    /**
     * pdo_pgsql reports every failed connection as SQLSTATE 08006, so the server's message tells them apart.
     * Only the category leaves this method; the message (user and host names) never does.
     */
    private static function postgresConnectError(DbalException $failure): string
    {
        $state = $failure instanceof DriverException ? (string) $failure->getSQLState() : '';
        $message = $failure->getMessage();

        return match (true) {
            preg_match('/password authentication failed|no password supplied|role "[^"]*" does not exist/', $message)
                === 1 => 'database_auth_failed',
            preg_match('/database "[^"]*" does not exist/', $message) === 1 => 'database_not_found',
            preg_match('/Connection refused|could not translate host name|timeout expired|No route to host'
                . '|Network is unreachable/i', $message) === 1 => 'database_unreachable',
            default => self::POSTGRES_ERRORS[$state] ?? 'database_connection_failed',
        };
    }

    private static function connectError(DbalException $failure): string
    {
        $code = $failure instanceof DriverException ? $failure->getCode() : 0;

        return self::CONNECT_ERRORS[$code] ?? 'database_connection_failed';
    }

    /**
     * Only the leading version number: the full string can carry build or distribution text.
     */
    private static function versionNumber(string $version): string
    {
        return preg_match('/^[0-9]+(\.[0-9]+){0,2}/', $version, $match) === 1 ? $match[0] : 'unknown';
    }
}
