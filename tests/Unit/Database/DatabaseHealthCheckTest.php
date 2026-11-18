<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Database;

use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\DatabaseHealthCheck;
use Nolviko\Core\Database\DatabaseHealthException;
use Nolviko\Core\Database\MysqlSession;
use Nolviko\Core\Database\PostgresSession;
use Nolviko\Core\Runtime\DiagnosticMessage;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M01.08 server verification rules without a server; the real MySQL 8.4 run is tests/Integration.
 */
final class DatabaseHealthCheckTest extends TestCase
{
    private const PASSWORD = 'Pa55-unit-never-shown';

    /**
     * @return array<string, string>
     */
    public static function healthyFacts(): array
    {
        return [
            'version' => '8.4.13',
            'charset_client' => 'utf8mb4',
            'charset_connection' => 'utf8mb4',
            'charset_results' => 'utf8mb4',
            'time_zone' => '+00:00',
            'sql_mode' => implode(',', MysqlSession::SQL_MODES),
        ];
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: list<string>}>
     */
    public static function serverCases(): array
    {
        $with = static fn(array $changes): array => [...self::healthyFacts(), ...$changes];

        return [
            'mysql 8.4' => [self::healthyFacts(), []],
            'mysql 8.4 with suffix' => [$with(['version' => '8.4.13-log']), []],
            'mariadb as mysql' => [$with(['version' => '11.4.4-MariaDB-ubu2404']), ['database_family_invalid:mariadb']],
            'mariadb compatibility prefix' => [$with(['version' => '5.5.5-10.11.6-MariaDB']),
                ['database_family_invalid:mariadb']],
            // Beta.10: MySQL 8.0 and newer, including 9.x; 5.7 and a future 10 are refused.
            'mysql 8.0' => [$with(['version' => '8.0.40']), []],
            'mysql 9' => [$with(['version' => '9.1.0-commercial']), []],
            'mysql 5.7' => [$with(['version' => '5.7.44-log']), ['database_version_unsupported:5.7.44']],
            'mysql 10' => [$with(['version' => '10.0.0']), ['database_version_unsupported:10.0.0']],
            'latin1 results' => [$with(['charset_results' => 'latin1']), ['database_charset_invalid']],
            'three-byte utf8' => [$with(['charset_connection' => 'utf8mb3']), ['database_charset_invalid']],
            'system time zone' => [$with(['time_zone' => 'SYSTEM']), ['database_timezone_invalid']],
            'empty sql mode' => [$with(['sql_mode' => '']), ['database_sql_mode_invalid']],
            'default mode without strict all' => [
                $with(['sql_mode' => 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,'
                    . 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION']),
                ['database_sql_mode_invalid'],
            ],
            'several problems' => [
                $with(['version' => '5.7.1', 'time_zone' => '+03:00', 'sql_mode' => 'ANSI']),
                ['database_version_unsupported:5.7.1', 'database_timezone_invalid', 'database_sql_mode_invalid'],
            ],
        ];
    }

    /**
     * @param array<string, string> $facts
     * @param list<string> $expected
     */
    /**
     * @return array<string, array{0: array<string, string>, 1: list<string>}>
     */
    public static function mariadbCases(): array
    {
        $with = static fn(array $changes): array => [...self::healthyFacts(), ...$changes];

        return [
            'mariadb 10.11' => [$with(['version' => '10.11.16-MariaDB']), []],
            'mariadb 11.4 build' => [$with(['version' => '11.4.4-MariaDB-ubu2404']), []],
            'compatibility prefix' => [$with(['version' => '5.5.5-10.11.6-MariaDB-log']), []],
            'mariadb 10.6' => [$with(['version' => '10.6.19-MariaDB']), []],
            'mariadb 10.5' => [$with(['version' => '10.5.27-MariaDB']), ['database_version_unsupported:10.5.27']],
            'mysql as mariadb' => [self::healthyFacts(), ['database_family_invalid:mysql']],
            'mariadb without strict all' => [
                $with(['version' => '10.11.16-MariaDB', 'sql_mode' => 'STRICT_TRANS_TABLES']),
                ['database_sql_mode_invalid'],
            ],
        ];
    }

    /**
     * @param array<string, string> $facts
     * @param list<string> $expected
     */
    #[DataProvider('mariadbCases')]
    public function testMariaDbServersAreVerified(array $facts, array $expected): void
    {
        self::assertSame($expected, DatabaseHealthCheck::mysqlDiagnostics($facts, 'mariadb'));
    }

    #[DataProvider('serverCases')]
    public function testServerFactsAreVerified(array $facts, array $expected): void
    {
        self::assertSame($expected, DatabaseHealthCheck::mysqlDiagnostics($facts));
    }

    public function testPostgresqlFactsNeedVersion14Utf8AndUtc(): void
    {
        $good = ['version' => '16.16 (Debian 16.16-1.pgdg13+1)', 'version_num' => '160016',
            'server_encoding' => 'UTF8', 'client_encoding' => 'UTF8', 'time_zone' => 'UTC'];

        self::assertSame([], DatabaseHealthCheck::postgresDiagnostics($good));
        self::assertSame([], DatabaseHealthCheck::postgresDiagnostics(['version_num' => '170002'] + $good));
        self::assertSame([], DatabaseHealthCheck::postgresDiagnostics(['version_num' => '140019'] + $good));
        self::assertSame(
            ['database_version_unsupported:13.18', 'database_charset_invalid', 'database_timezone_invalid'],
            DatabaseHealthCheck::postgresDiagnostics(['version' => '13.18', 'version_num' => '130018',
                'server_encoding' => 'SQL_ASCII', 'time_zone' => 'Europe/Istanbul'] + $good),
        );
    }

    public function testPostgresqlUnresolvableHostIsUnreachableWithoutCredentialsInTheMessage(): void
    {
        try {
            DatabaseHealthCheck::check(
                ConnectionFactory::create(self::config('postgresql', 'db.invalid')),
                'postgresql',
            );
            self::fail('expected a connection failure');
        } catch (DatabaseHealthException $failure) {
            // Without pdo_pgsql (the release-check image) PDO cannot even try: a plain connection failure.
            self::assertSame(
                [extension_loaded('pdo_pgsql') ? 'database_unreachable' : 'database_connection_failed'],
                $failure->diagnostics(),
            );
            self::assertNull($failure->getPrevious(), 'the driver exception quotes host and user');
            self::assertStringNotContainsString('db.invalid', $failure->getMessage());
        }
    }

    public function testUnresolvableHostIsUnreachableWithoutCredentialsInTheMessage(): void
    {
        try {
            DatabaseHealthCheck::check(ConnectionFactory::create(self::config('mysql', 'db.invalid')), 'mysql');
            self::fail('expected a connection failure');
        } catch (DatabaseHealthException $failure) {
            self::assertSame(['database_unreachable'], $failure->diagnostics());
            self::assertSame('database_unreachable', $failure->getMessage());
            self::assertNull($failure->getPrevious(), 'the driver exception quotes host and user');
        }
    }

    public function testMysqlConnectionsCarrySessionSetupAndTimeout(): void
    {
        $connection = ConnectionFactory::create(self::config('mysql'));
        $middlewares = $connection->getConfiguration()->getMiddlewares();

        self::assertCount(1, $middlewares);
        self::assertInstanceOf(MysqlSession::class, $middlewares[0]);
        self::assertSame(
            [PDO::ATTR_TIMEOUT => ConnectionFactory::CONNECT_TIMEOUT_SECONDS],
            $connection->getParams()['driverOptions'] ?? null,
        );
        self::assertSame(
            "SET NAMES 'utf8mb4', time_zone = '+00:00', sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,"
                . "STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
            MysqlSession::initStatement(),
        );
        $postgres = ConnectionFactory::create(self::config('postgresql'));
        $middlewares = $postgres->getConfiguration()->getMiddlewares();
        self::assertCount(1, $middlewares);
        self::assertInstanceOf(PostgresSession::class, $middlewares[0]);
        self::assertSame(
            [PDO::ATTR_TIMEOUT => ConnectionFactory::CONNECT_TIMEOUT_SECONDS],
            $postgres->getParams()['driverOptions'] ?? null,
        );
        self::assertSame("SET client_encoding = 'UTF8'; SET TIME ZONE 'UTC'", PostgresSession::initStatement());
        self::assertSame('2026-01-01 10:00:00.000000', PostgresSession::timestamp('2026-01-01 10:00:00'));
        self::assertSame('2026-01-01 10:00:00.500000', PostgresSession::timestamp('2026-01-01 10:00:00.5'));
        self::assertSame('2026-01-01 10:00:00.123456', PostgresSession::timestamp('2026-01-01 10:00:00.123456+00'));
        self::assertSame('not a date', PostgresSession::timestamp('not a date'));
    }

    public function testDiagnosticsMapToTranslatedMessagesWithParameters(): void
    {
        self::assertSame(
            ['diagnostics.database_version_unsupported', ['version' => '8.0.40']],
            DiagnosticMessage::fromDiagnostic('database_version_unsupported:8.0.40'),
        );
        self::assertSame(
            ['diagnostics.database_backend_unverified', ['driver' => 'mariadb']],
            DiagnosticMessage::fromDiagnostic('database_backend_unverified:mariadb'),
        );
    }

    private static function config(string $driver, string $host = '192.0.2.1'): Config
    {
        return new Config($driver, $host, null, 'forum', 'forum', new Secret(self::PASSWORD), new Secret(
            str_repeat('s', 32),
        ));
    }
}
