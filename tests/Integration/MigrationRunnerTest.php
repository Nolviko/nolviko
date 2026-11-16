<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\Migration\Core\CoreFoundation;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationLease;
use Nolviko\Core\Database\Migration\MigrationPhase;
use Nolviko\Core\Database\Migration\MigrationRegistry;
use Nolviko\Core\Database\Migration\MigrationRunner;
use Nolviko\Tests\Performance\BenchmarkHarness;
use Nolviko\Tests\Support\FixtureMigration;
use Nolviko\Tests\Support\QueryCounter;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M01.09 on a real MySQL 8.4. The test database must not hold a forum: tables are dropped between tests, and
 * the suite refuses to run when `settings` has rows.
 */
final class MigrationRunnerTest extends TestCase
{
    private const TABLES = ['m0109_resume', 'm0109_gate', 'settings', 'migration_leases', 'migration_history'];

    private Connection $connection;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->connection = self::connect();

        // The shared test check: the test-database marker, or no settings at all (never an installed forum).
        // Other suites leave version-counter rows in `settings` of the marked test database, so a plain row
        // count refused it depending on the test order.
        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        self::dropTables($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            self::dropTables($this->connection);
        }
    }

    public function testFreshInstallCreatesTablesAndRerunIsANoOp(): void
    {
        $counter = new QueryCounter();
        $runner = self::runner(self::connect([$counter]));

        self::assertSame([CoreFoundation::ID], $runner->pending());

        $report = $runner->run();

        self::assertTrue($report->complete);
        self::assertSame([CoreFoundation::ID], $report->applied);
        self::assertSame(self::foundationSteps(), $report->steps);
        self::assertSame([], $runner->pending());
        self::assertTrue($this->connection->createSchemaManager()->tablesExist(
            ['migration_history', 'migration_leases', 'settings'],
        ));

        $row = $this->connection->fetchAssociative('SELECT * FROM migration_history');

        self::assertIsArray($row);
        self::assertSame(CoreFoundation::ID, $row['id']);
        self::assertSame(MigrationRegistry::checksum(new CoreFoundation(), TestDatabase::driver()), $row['checksum']);
        self::assertSame(
            ['expand', 'applied', self::foundationSteps()],
            [$row['phase'], $row['status'], (int) $row['completed_steps']],
        );
        self::assertNotNull($row['applied_at']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM migration_leases'));

        $before = $counter->count;
        $again = $runner->run();

        self::assertSame([[], 0, true], [$again->applied, $again->steps, $again->complete]);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM migration_history'));
        self::assertLessThan($before, $counter->count - $before, 're-run must cost less than the install');
    }

    public function testChangedChecksumRefusesToRun(): void
    {
        self::runner($this->connection)->run();
        $this->connection->update(
            'migration_history',
            ['checksum' => str_repeat('0', 64)],
            ['id' => CoreFoundation::ID],
        );

        self::assertDiagnostic('migration_checksum_mismatch:' . CoreFoundation::ID, self::runner($this->connection));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM migration_leases'));
    }

    public function testUnknownMigrationInHistoryRefusesToRun(): void
    {
        $runner = self::runner($this->connection, [new FixtureMigration('0002_later')]);
        $runner->run();

        self::assertDiagnostic('migration_unknown:0002_later', self::runner($this->connection));
    }

    public function testFailedStepResumesFromItsCheckpoint(): void
    {
        $migration = new FixtureMigration('0002_resume', [
            'CREATE TABLE IF NOT EXISTS m0109_resume (n INT NOT NULL)',
            'INSERT INTO m0109_resume (n) VALUES (1)',
            'INSERT INTO m0109_resume (n) SELECT 2 FROM m0109_gate',
            'INSERT INTO m0109_resume (n) VALUES (3)',
        ]);

        self::assertDiagnostic('migration_step_failed:0002_resume:2', self::runner($this->connection, [$migration]));
        self::assertSame(
            ['running', 2],
            [$this->historyStatus('0002_resume'), (int) $this->connection->fetchOne(
                "SELECT completed_steps FROM migration_history WHERE id = '0002_resume'",
            )],
        );
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM migration_leases'));

        $this->connection->executeStatement('CREATE TABLE m0109_gate (x INT)');
        $this->connection->executeStatement('INSERT INTO m0109_gate VALUES (1)');
        $report = self::runner($this->connection, [$migration])->run();

        self::assertSame([['0002_resume'], 2], [$report->applied, $report->steps]);
        self::assertSame(
            [1, 2, 3],
            array_map(intval(...), $this->connection->fetchFirstColumn('SELECT n FROM m0109_resume ORDER BY n')),
            'step 1 ran exactly once',
        );
    }

    public function testStepsAreBoundedPerCall(): void
    {
        $runner = self::runner($this->connection, [new FixtureMigration('0002_more', ['SELECT 1', 'SELECT 2'])]);
        $calls = [];

        do {
            $report = $runner->run(maxSteps: 2);
            $calls[] = $report->steps;
        } while (!$report->complete);

        // The foundation's steps plus two, two per call.
        $total = self::foundationSteps() + 2;
        self::assertSame(array_merge(array_fill(0, intdiv($total, 2), 2), $total % 2 === 1 ? [1] : []), $calls);
        self::assertSame([], $runner->pending());
    }

    public function testTimeBudgetStopsAtADurableCheckpointAndCanResume(): void
    {
        $runner = self::runner($this->connection);
        $first = $runner->run(continue: static fn(): bool => false);
        self::assertSame(1, $first->steps);
        self::assertFalse($first->complete);
        $resumed = $runner->run();
        self::assertTrue($resumed->complete);
        self::assertSame([], $runner->pending());
        self::assertSame(0, $runner->run()->steps);
    }

    public function testContractPhaseNeedsAnExplicitAdminRun(): void
    {
        $drop = new FixtureMigration('0002_drop', ['SELECT 1'], MigrationPhase::Contract);
        $runner = self::runner($this->connection, [$drop]);

        self::assertDiagnostic('migration_contract_requires_admin:0002_drop', $runner);
        self::assertSame(['0002_drop'], $runner->pending());
        self::assertTrue($runner->run(allowContract: true)->complete);
    }

    public function testLeaseHeldByAnotherProcessBlocksTheRun(): void
    {
        self::runner($this->connection)->run(); // tables exist, so the child can take the lease
        $child = proc_open(
            [PHP_BINARY, __DIR__ . '/hold-lease.php', '3'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($child);

        try {
            // No stderr read before this point: it would block until the child exits and releases the lease.
            self::assertSame("held\n", fgets($pipes[1]));
            $blocked = self::runner($this->connection, [new FixtureMigration('0002_x')]);
            self::assertDiagnostic('migration_locked', $blocked);
            self::assertSame("released\n", fgets($pipes[1]));
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($child);
        }

        self::assertTrue(self::runner($this->connection, [new FixtureMigration('0002_x')])->run()->complete);
    }

    public function testExpiredLeaseIsTakenOverAndTheOldHolderLosesIt(): void
    {
        self::runner($this->connection)->run();
        $past = static fn(): DateTimeImmutable => new DateTimeImmutable('-1 hour');
        $stale = new MigrationLease($this->connection, $past, 60);
        $stale->acquire();

        self::assertTrue(self::runner($this->connection, [new FixtureMigration('0002_y')])->run()->complete);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('migration_lease_lost');

        $stale->renew();
    }

    /**
     * Benchmark case `migration_fresh_install`: install from an empty database, all steps in one call.
     */
    public function testFreshInstallCost(): void
    {
        $counter = new QueryCounter();
        $connection = self::connect([$counter]);
        $queries = 0;
        $result = BenchmarkHarness::measure(static function () use ($connection, $counter, &$queries): void {
            self::dropTables($connection);
            $counter->count = 0;
            self::runner($connection)->run();
            $queries = $counter->count;
        }, 10, 1);

        fwrite(STDERR, sprintf(
            "migration_fresh_install p50 %.4f ms  p95 %.4f ms  peak %d MiB  queries %d (incl. table drops)\n",
            $result['p50_ms'],
            $result['p95_ms'],
            $result['max_peak_bytes'] / 1048576,
            $queries,
        ));
        self::assertLessThan(50, $queries);
        self::assertLessThan(5000.0, $result['p95_ms']);
    }

    /** The foundation migration's step count on the backend under test (PostgreSQL adds its clock function). */
    private static function foundationSteps(): int
    {
        return count(MigrationRegistry::statements(new CoreFoundation(), TestDatabase::driver()));
    }

    private function historyStatus(string $id): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM migration_history WHERE id = ?', [$id]);
    }

    private static function assertDiagnostic(string $diagnostic, MigrationRunner $runner): void
    {
        try {
            $runner->run();
            self::fail('expected ' . $diagnostic);
        } catch (MigrationException $failure) {
            self::assertSame($diagnostic, $failure->diagnostic);
            self::assertSame($diagnostic, $failure->getMessage());
        }
    }

    /**
     * @param list<FixtureMigration> $more Appended after CoreFoundation.
     */
    private static function runner(Connection $connection, array $more = []): MigrationRunner
    {
        $registry = new MigrationRegistry([new CoreFoundation(), ...$more]);

        return new MigrationRunner($connection, $registry, TestDatabase::driver());
    }

    private static function dropTables(Connection $connection): void
    {
        foreach (self::TABLES as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /**
     * @param list<QueryCounter> $middlewares
     */
    private static function connect(array $middlewares = []): Connection
    {
        return ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        ), $middlewares);
    }
}
