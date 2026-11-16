<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\DatabaseHealthCheck;
use Nolviko\Core\Database\DatabaseHealthException;
use Nolviko\Tests\Performance\BenchmarkHarness;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M01.08 against a real MySQL 8.4 (Docker reference db), MariaDB or PostgreSQL 16 (2026-11-14), per
 * FORUM_TEST_DB_DRIVER. Needs FORUM_TEST_DB_HOST, _NAME, _USER and _PASSWORD; skipped otherwise.
 */
final class MysqlHealthCheckTest extends TestCase
{
    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }
    }

    public function testRealServerPassesAndSessionSettingsComeFromTheConnection(): void
    {
        $facts = DatabaseHealthCheck::check(self::connect(), TestDatabase::driver());

        if (TestDatabase::driver() === 'postgresql') {
            self::assertGreaterThanOrEqual(DatabaseHealthCheck::POSTGRES_MINIMUM, (int) $facts['version_num']);
            self::assertSame('UTF8', $facts['client_encoding']);
            // The role/server default may differ: seeing UTC proves PostgresSession ran.
            self::assertSame('UTC', $facts['time_zone']);

            return;
        }

        if (TestDatabase::driver() === 'mariadb') {
            self::assertStringContainsStringIgnoringCase('mariadb', $facts['version']);
            self::assertTrue(version_compare($facts['version'], DatabaseHealthCheck::MARIADB_MINIMUM, '>='));
        } else {
            self::assertStringStartsWith(DatabaseHealthCheck::MYSQL_SERIES, $facts['version']);
        }
        self::assertSame('utf8mb4', $facts['charset_connection']);
        self::assertSame('+00:00', $facts['time_zone']);
        // The server default lacks STRICT_ALL_TABLES: seeing it proves MysqlSession ran.
        self::assertStringContainsString('STRICT_ALL_TABLES', $facts['sql_mode']);
    }

    public function testStrictModeRejectsTruncationAndBadDates(): void
    {
        $connection = self::connect();
        // PostgreSQL is always strict; the same inserts must fail there too.
        $connection->executeStatement('CREATE TEMPORARY TABLE m0108_strict (name VARCHAR(3), at '
            . (TestDatabase::driver() === 'postgresql' ? 'TIMESTAMP' : 'DATETIME') . ')');

        foreach ([['abcd', null], ['abc', '0000-00-00 00:00:00']] as $row) {
            try {
                $connection->insert('m0108_strict', ['name' => $row[0], 'at' => $row[1]]);
                self::fail('strict mode accepted ' . json_encode($row));
            } catch (DriverException) {
                self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM m0108_strict'));
            }
        }
    }

    public function testChangedSessionIsDetected(): void
    {
        $connection = self::connect();
        $postgres = TestDatabase::driver() === 'postgresql';
        $connection->executeStatement($postgres ? "SET TIME ZONE 'Europe/Istanbul'"
            : "SET time_zone = '+03:00', sql_mode = ''");

        try {
            DatabaseHealthCheck::check($connection, TestDatabase::driver());
            self::fail('expected a session failure');
        } catch (DatabaseHealthException $failure) {
            self::assertSame(
                $postgres ? ['database_timezone_invalid'] : ['database_timezone_invalid', 'database_sql_mode_invalid'],
                $failure->diagnostics(),
            );
        }
    }

    public function testWrongPasswordUnknownUserUnknownDatabaseAndUnreachableHost(): void
    {
        $password = 'wrong-' . bin2hex(random_bytes(6));
        $cases = [
            ['database_auth_failed', self::config(password: $password)],
            ['database_auth_failed', self::config(user: 'no_such_user_m0108', password: $password)],
            ['database_not_found', self::config(database: 'no_such_database_m0108')],
            ['database_unreachable', self::config(port: 1)],
        ];

        foreach ($cases as [$expected, $config]) {
            try {
                DatabaseHealthCheck::check(ConnectionFactory::create($config), TestDatabase::driver());
                self::fail('expected ' . $expected);
            } catch (DatabaseHealthException $failure) {
                self::assertSame([$expected], $failure->diagnostics());

                foreach ([$password, (string) getenv('FORUM_TEST_DB_PASSWORD'), 'no_such_user_m0108'] as $secret) {
                    self::assertStringNotContainsString($secret, $failure->getMessage());
                }
            }
        }
    }

    /**
     * Benchmark case `database_connect_health`: a fresh connection (TCP, auth, session SET) plus the check,
     * the installer's cost per attempt. Printed to stderr for run-checks; budget is generous (shared hosts).
     */
    public function testConnectAndCheckCost(): void
    {
        $sql = 0;
        $result = BenchmarkHarness::measure(static function () use (&$sql): void {
            $connection = self::connect();
            DatabaseHealthCheck::check($connection, TestDatabase::driver());
            $connection->close();
            $sql += 2;
        }, 50, 5);

        fwrite(STDERR, sprintf(
            "database_connect_health p50 %.4f ms  p95 %.4f ms  peak %d MiB  sql/attempt %d
",
            $result['p50_ms'],
            $result['p95_ms'],
            $result['max_peak_bytes'] / 1048576,
            $sql / 55,
        ));
        self::assertLessThan(250.0, $result['p95_ms']);
    }

    private static function connect(): Connection
    {
        return ConnectionFactory::create(self::config());
    }

    private static function config(
        ?string $user = null,
        ?string $password = null,
        ?string $database = null,
        ?int $port = null,
    ): Config {
        return new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            $port,
            $database ?? (string) getenv('FORUM_TEST_DB_NAME'),
            $user ?? (string) getenv('FORUM_TEST_DB_USER'),
            new Secret($password ?? (string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        );
    }
}
