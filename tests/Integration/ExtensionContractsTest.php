<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\ExtensionEvents;
use Nolviko\Core\Database\ExtensionMigrations;
use Nolviko\Core\Database\OutboxQueue;
use Nolviko\Core\Events\CooperativeTaskRunner;
use Nolviko\Core\Events\Outbox;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Core\Extensions\ExtensionCatalog;
use Nolviko\Core\Extensions\ExtensionFailure;
use Nolviko\Core\Extensions\ExtensionManifest;
use Nolviko\Core\Extensions\ExtensionRegistry;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Real-engine durable extension schema, retry and duplicate-delivery evidence on disposable databases only. */
final class ExtensionContractsTest extends TestCase
{
    private const TABLE = 'ext_66508e542129881903648a62d8e352ca_records';

    private Connection $connection;

    private TemporaryDirectory $files;

    private ExtensionCatalog $catalog;

    private ExtensionManifest $manifest;

    private ExtensionMigrations $migrations;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->connection = ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        ));
        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        TestDatabase::reset($this->connection);
        $this->files = new TemporaryDirectory();
        $this->files->write('extensions/acme/hello/extension.json', json_encode([
            'id' => 'acme/hello', 'name' => 'Hello', 'api' => 1, 'version' => '1.0.0',
            'engine' => '^1', 'php' => '^8.4', 'license' => 'MIT', 'migrations' => ['0001_records'],
        ], JSON_THROW_ON_ERROR));
        $this->files->write('extensions/acme/hello/migrations/0001_records.json', json_encode([
            'tables' => ['records' => ['id' => 'bigint', 'value' => 'integer']],
        ], JSON_THROW_ON_ERROR));
        $this->catalog = new ExtensionCatalog($this->files->path . '/extensions');
        $this->manifest = $this->catalog->discover()['manifests']['acme/hello'];
        self::assertSame(self::TABLE, $this->manifest->table('records'));
        $this->migrations = new ExtensionMigrations($this->connection, $this->catalog);
    }

    protected function tearDown(): void
    {
        if (isset($this->files)) {
            $this->files->remove();
        }

        if (isset($this->connection)) {
            if ($this->connection->createSchemaManager()->tablesExist([self::TABLE])) {
                $this->connection->executeStatement('DROP TABLE ' . self::TABLE);
            }

            TestDatabase::release($this->connection);
        }
    }


    public function testExpandMigrationIsRepeatableAndChangedHistoryIsBlocked(): void
    {
        $this->migrations->apply($this->manifest);
        $this->migrations->apply($this->manifest);
        self::assertTrue($this->connection->createSchemaManager()->tablesExist([self::TABLE]));
        self::assertSame('done', $this->connection->fetchOne('SELECT status FROM extension_schema'));
        $this->files->write('extensions/acme/hello/migrations/0001_records.json', '{}');
        $this->expectExceptionMessage('extension_migration_changed');
        $this->migrations->apply($this->manifest);
    }

    public function testFailedCheckpointCanResumeWithoutDestructiveRollback(): void
    {
        $this->connection->executeStatement('CREATE TABLE ' . self::TABLE . ' (different_column INT NOT NULL)');
        try {
            $this->migrations->apply($this->manifest);
            self::fail('schema conflict accepted');
        } catch (ExtensionFailure $failure) {
            self::assertSame('extension_migration_failed', $failure->getMessage());
        }

        self::assertSame('failed', $this->connection->fetchOne('SELECT status FROM extension_schema'));
        self::assertTrue($this->connection->createSchemaManager()->tablesExist([self::TABLE]));
        $this->connection->executeStatement('DROP TABLE ' . self::TABLE);
        $this->migrations->apply($this->manifest);
        self::assertSame('done', $this->connection->fetchOne('SELECT status FROM extension_schema'));
    }

    public function testDuplicateSubscriberDeliveryCommitsOnlyOneEffect(): void
    {
        $this->migrations->apply($this->manifest);
        $registry = new ExtensionRegistry();
        $registry->subscribers['post.created']['acme/hello'] = static function (
            array $payload,
            Connection $db,
            Closure $guard,
        ): void {
            $guard();
            $db->insert(self::TABLE, ['id' => $payload['id'], 'value' => 1]);
        };
        $handler = (new ExtensionEvents($this->connection, $registry))->handlers()['post.created'];
        $lease = new OutboxLease(123, 'post.created', 1, '{"id":1}', str_repeat('a', 32), 1, 1);
        $this->connection->transactional(static function () use ($handler, $lease): void {
            $handler($lease, static fn() => null);
            $handler($lease, static fn() => null);
        });
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM extension_event_receipts'));
    }

    public function testFailureRollsBackEffectsAndReceiptsAndRetriesDurably(): void
    {
        $this->migrations->apply($this->manifest);
        $this->connection->transactional(fn(): bool => (new Outbox($this->connection))->record(
            'post.created',
            'post',
            1,
            ['id' => 1],
        ));
        $fail = true;
        $registry = new ExtensionRegistry();
        $registry->subscribers['post.created']['acme/hello'] = static function (
            array $payload,
            Connection $db,
            Closure $guard,
        ) use (&$fail): void {
            $db->insert(self::TABLE, ['id' => 1, 'value' => 1]);
            if ($fail) {
                throw new ExtensionFailure('fixture_retry');
            }
        };
        $runner = new CooperativeTaskRunner(
            $this->connection,
            new OutboxQueue($this->connection),
            (new ExtensionEvents($this->connection, $registry))->handlers(),
            static fn(): bool => true,
        );
        self::assertSame(1, $runner->run(1, 5000)['failed']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM extension_event_receipts'));
        $fail = false;
        $this->connection->executeStatement("UPDATE outbox_events SET available_at = '2000-01-01 00:00:00'");
        self::assertSame(1, $runner->run(1, 5000)['completed']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM extension_event_receipts'));
    }
}
