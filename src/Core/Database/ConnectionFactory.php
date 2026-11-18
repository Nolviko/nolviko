<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use LogicException;
use Nolviko\Core\Config\Config;
use PDO;

/**
 * Builds the DBAL connection for the selected backend (ADR-006). Building never connects: DBAL opens the
 * connection on the first query, so routes that do not query never touch the database.
 *
 * MySQL-family connections get MysqlSession (utf8mb4, UTC, strict SQL mode) and a short connect timeout, so
 * an unreachable server fails the request quickly instead of running into PHP's execution limit.
 */
final class ConnectionFactory
{
    public const CONNECT_TIMEOUT_SECONDS = 5;

    /** Runtime-policy driver names => DBAL drivers. */
    private const DRIVERS = [
        'mysql' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'postgresql' => 'pdo_pgsql',
    ];

    /**
     * @param list<Middleware> $middlewares Extra DBAL middlewares (tests: query counting), inside MysqlSession.
     *
     * @throws LogicException `config_missing` before installation (no database is configured yet).
     * @throws InvalidArgumentException For a driver outside the portability contract.
     */
    public static function create(?Config $config, array $middlewares = []): Connection
    {
        if ($config === null) {
            throw new LogicException('config_missing');
        }

        $driver = self::DRIVERS[$config->databaseDriver] ?? throw new InvalidArgumentException(
            'database_driver_unsupported',
        );
        $parameters = [
            'driver' => $driver,
            'host' => $config->databaseHost,
            'dbname' => $config->databaseName,
            'user' => $config->databaseUser,
            // The only place the password leaves its Secret: handed straight to the driver.
            'password' => $config->databasePassword->reveal(),
        ];

        if ($config->databasePort !== null) {
            $parameters['port'] = $config->databasePort;
        }

        $configuration = new Configuration();

        if ($driver === 'pdo_mysql') {
            $parameters['charset'] = MysqlSession::CHARSET;
            $parameters['driverOptions'] = [PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS];
            $middlewares[] = new MysqlSession();
        } else {
            $parameters['driverOptions'] = [PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS];
            // Innermost: it reads column types from the PDO statement right below it.
            array_unshift($middlewares, new PostgresSession());
            // PostgreSQL 18 reports RESTRICT violations as 23001 (beta.10 CI matrix).
            $middlewares[] = new PostgresRestrictViolation();
        }

        $configuration->setMiddlewares($middlewares);

        return DriverManager::getConnection($parameters, $configuration);
    }
}
