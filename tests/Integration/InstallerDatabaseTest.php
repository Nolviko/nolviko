<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationRegistry;
use Nolviko\Core\Database\Migration\SchemaInstaller;
use Nolviko\Core\Installer\DatabaseSetup;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M01.14 on a real MySQL 8.4: the installer tests real credentials before writing, and creates the schema in
 * resumable, time-bounded batches. Same safety rule as MigrationRunnerTest (refuses an installed forum).
 */
final class InstallerDatabaseTest extends TestCase
{
    private Connection $connection;

    private TemporaryDirectory $site;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->connection = self::connect();
        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        $this->dropTables();
        $this->site = new TemporaryDirectory();
        mkdir($this->site->path . '/config');
        mkdir($this->site->path . '/public');
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->dropTables();
            $this->site->remove();
        }
    }

    public function testRealCredentialsAreVerifiedBeforeWriting(): void
    {
        $setup = new DatabaseSetup($this->site->path . '/config', $this->site->path . '/public');
        $wrong = $setup->configure(['password' => 'wrong-' . bin2hex(random_bytes(4))] + self::input());

        self::assertSame(['database_auth_failed'], $wrong);
        self::assertFileDoesNotExist($this->site->path . '/config/local.php');

        self::assertSame([], $setup->configure(self::input()));

        $config = ConfigLoader::load($this->site->path . '/config/local.php', []);

        self::assertSame((string) getenv('FORUM_TEST_DB_NAME'), $config?->databaseName);
        self::assertSame(TestDatabase::driver(), $config?->databaseDriver);
    }

    public function testSchemaIsCreatedInResumableBatches(): void
    {
        // A zero budget runs exactly one step per request: the slowest possible host.
        $connect = static fn(): Connection => self::connect();
        $installer = new SchemaInstaller($connect, MigrationRegistry::core(), budget: 0.0);
        $expectedSteps = array_sum(array_map(
            static fn(Migration $migration): int => count(MigrationRegistry::statements(
                $migration,
                TestDatabase::driver(),
            )),
            MigrationRegistry::core()->migrations,
        ));
        $batches = [];

        do {
            $batches[] = $installer->run();
        } while (!end($batches)['complete'] && count($batches) <= $expectedSteps);

        self::assertSame(array_fill(0, $expectedSteps, 1), array_column($batches, 'steps'));
        self::assertTrue(end($batches)['complete']);
        self::assertSame([], $installer->pending());
        self::assertSame(['steps' => 0, 'complete' => true], $installer->run(), 'repeating is a no-op');
    }

    public function testOneRequestFinishesTheCoreSchemaWithinTheBudget(): void
    {
        $installer = new SchemaInstaller(static fn(): Connection => self::connect(), MigrationRegistry::core());
        $started = microtime(true);
        $result = $installer->run();
        $seconds = microtime(true) - $started;

        self::assertTrue($result['complete']);
        self::assertLessThan(SchemaInstaller::TIME_BUDGET_SECONDS, $seconds);
        fwrite(STDERR, sprintf("installer_schema_batch %.1f ms, %d steps\n", $seconds * 1000, $result['steps']));
    }

    private function dropTables(): void
    {
        TestDatabase::dropAll($this->connection);
    }

    /**
     * @return array<string, string>
     */
    private static function input(): array
    {
        return [
            // The installer's type choice: the MySQL family (detected) or PostgreSQL.
            'driver' => TestDatabase::driver() === 'postgresql' ? 'postgresql' : 'mysql',
            'host' => (string) getenv('FORUM_TEST_DB_HOST'),
            'port' => '',
            'name' => (string) getenv('FORUM_TEST_DB_NAME'),
            'user' => (string) getenv('FORUM_TEST_DB_USER'),
            'password' => (string) getenv('FORUM_TEST_DB_PASSWORD'),
        ];
    }

    private static function connect(): Connection
    {
        return ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        ));
    }
}
