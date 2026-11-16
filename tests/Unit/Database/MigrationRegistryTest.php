<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Database;

use InvalidArgumentException;
use Nolviko\Core\Database\Migration\Core\CoreFoundation;
use Nolviko\Core\Database\Migration\MariaDbDialect;
use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;
use Nolviko\Core\Database\Migration\MigrationRegistry;
use Nolviko\Core\Runtime\DiagnosticMessage;
use Nolviko\Tests\Support\FixtureMigration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M01.09 registry order, checksums and the first migration's shape, without a database.
 */
final class MigrationRegistryTest extends TestCase
{
    /** Words reserved in MySQL 8.4, MariaDB 11.4 or PostgreSQL 16 that a forum schema is tempted to use. */
    private const RESERVED = ['check', 'checkpoint', 'condition', 'current', 'default', 'group', 'groups',
        'index', 'key', 'keys', 'lock', 'option', 'order', 'range', 'rank', 'read', 'session', 'system', 'table',
        'to', 'user', 'value', 'values', 'window', 'with'];

    public function testCoreRegistryStartsWithTheFoundation(): void
    {
        $registry = MigrationRegistry::core();

        self::assertInstanceOf(CoreFoundation::class, $registry->migrations[0]);
        self::assertSame($registry->migrations[0], $registry->find(CoreFoundation::ID));
        self::assertNull($registry->find('9999_missing'));
    }

    /**
     * @return array<string, array{0: list<Migration>, 1: string}>
     */
    public static function invalidRegistries(): array
    {
        $core = new CoreFoundation();

        return [
            'bad id' => [[$core, new FixtureMigration('0002-Users')], '0002-Users'],
            'duplicate id' => [[$core, new FixtureMigration('0002_a'), new FixtureMigration('0002_a')], '0002_a'],
            'out of order' => [[$core, new FixtureMigration('0003_b'), new FixtureMigration('0002_a')], '0002_a'],
            'foundation missing' => [[new FixtureMigration('0000_first')], '0000_first'],
            'foundation not first' => [[new FixtureMigration('0000_first'), $core], '0000_first'],
        ];
    }

    /**
     * @param list<Migration> $migrations
     */
    #[DataProvider('invalidRegistries')]
    public function testInvalidRegistriesAreRejected(array $migrations, string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('migration_registry_invalid:' . $id);

        new MigrationRegistry($migrations);
    }

    public function testChecksumIsStableAndCoversEveryInput(): void
    {
        $base = MigrationRegistry::checksum(new FixtureMigration('0002_a', ['SELECT 1']), 'mysql');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $base);
        self::assertSame($base, MigrationRegistry::checksum(new FixtureMigration('0002_a', ['SELECT 1']), 'mysql'));

        $variants = [
            MigrationRegistry::checksum(new FixtureMigration('0002_b', ['SELECT 1']), 'mysql'),
            MigrationRegistry::checksum(new FixtureMigration('0002_a', ['SELECT 2']), 'mysql'),
            MigrationRegistry::checksum(new FixtureMigration('0002_a', ['SELECT', '1']), 'mysql'),
            MigrationRegistry::checksum(new FixtureMigration('0002_a', ['SELECT 1'], MigrationPhase::Migrate), 'mysql'),
            MigrationRegistry::checksum(new FixtureMigration('0002_a', ['SELECT 1']), 'mariadb'),
        ];

        self::assertNotContains($base, $variants);
        self::assertCount(count($variants), array_unique($variants));
    }

    public function testFoundationIsIdempotentInnoDbUtf8mb4AndAvoidsReservedWords(): void
    {
        $statements = (new CoreFoundation())->statements('mysql');

        self::assertCount(3, $statements);
        self::assertStringContainsString('migration_history', $statements[0]);
        self::assertStringContainsString('migration_leases', $statements[1]);
        self::assertStringContainsString('settings', $statements[2]);

        foreach ($statements as $statement) {
            self::assertStringStartsWith('CREATE TABLE IF NOT EXISTS ', $statement);
            self::assertStringEndsWith('ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci', $statement);
            preg_match_all('/(?:EXISTS |\(|, )([a-z_]+) /', $statement, $names);

            foreach ($names[1] as $name) {
                self::assertNotContains($name, self::RESERVED, $statement);
            }
        }
    }

    public function testFoundationIsNotWrittenForOtherBackendsYet(): void
    {
        foreach (['mariadb', 'postgresql'] as $backend) {
            try {
                (new CoreFoundation())->statements($backend);
                self::fail('expected ' . $backend . ' to be unsupported');
            } catch (MigrationException $failure) {
                self::assertSame('migration_backend_unsupported:' . $backend, $failure->diagnostic);
            }
        }
    }

    public function testMariaDbRunsTheMysqlStatementsWithItsCollation(): void
    {
        $foundation = new CoreFoundation();
        $mysql = MigrationRegistry::statements($foundation, 'mysql');
        $mariadb = MigrationRegistry::statements($foundation, MariaDbDialect::BACKEND);

        self::assertCount(count($mysql), $mariadb);
        self::assertStringContainsString('utf8mb4_0900_ai_ci', implode("
", $mysql));
        self::assertStringNotContainsString('utf8mb4_0900_ai_ci', implode("
", $mariadb));
        self::assertStringContainsString(MariaDbDialect::COLLATION, implode("
", $mariadb));
        self::assertNotSame(
            MigrationRegistry::checksum($foundation, 'mysql'),
            MigrationRegistry::checksum($foundation, MariaDbDialect::BACKEND),
            'each backend records its own checksum',
        );

        foreach (MigrationRegistry::core()->migrations as $migration) {
            self::assertStringNotContainsString(
                '0900',
                implode("
", MigrationRegistry::statements($migration, MariaDbDialect::BACKEND)),
                $migration->id(),
            );
        }
    }

    public function testDiagnosticsCarryTheMigration(): void
    {
        self::assertSame(
            ['diagnostics.migration_step_failed', ['migration' => '0001_core_foundation:2']],
            DiagnosticMessage::fromDiagnostic('migration_step_failed:0001_core_foundation:2'),
        );
        self::assertSame(['diagnostics.migration_locked', []], DiagnosticMessage::fromDiagnostic('migration_locked'));
    }
}
