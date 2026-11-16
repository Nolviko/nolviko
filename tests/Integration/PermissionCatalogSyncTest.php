<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\Migration\MigrationRegistry;
use Nolviko\Core\Database\Migration\SchemaInstaller;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionDefinition;
use Nolviko\Modules\Permissions\Infrastructure\PermissionCatalogSync;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M02.07 on MySQL 8.4: the code catalog is synced into `permissions` idempotently after migrations.
 */
final class PermissionCatalogSyncTest extends TestCase
{
    private Connection $connection;

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

        TestDatabase::dropAll($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::dropAll($this->connection);
        }
    }

    public function testInstallerSyncsTheCoreCatalogOnceTheSchemaIsComplete(): void
    {
        $syncs = [];
        $installer = new SchemaInstaller(
            fn(): Connection => $this->connection,
            MigrationRegistry::core(),
            afterComplete: [
                static function (Connection $db) use (&$syncs): void {
                    $syncs[] = (new PermissionCatalogSync($db))->sync(PermissionCatalog::core());
                },
            ],
        );

        $installer->run();
        $installer->run();

        self::assertSame(
            [['added' => count(PermissionCatalog::core()->all()), 'changed' => 0, 'retired' => 0],
                ['added' => 0, 'changed' => 0, 'retired' => 0]],
            $syncs,
            'the second run writes nothing',
        );
        self::assertSame(
            ['enum', 'all,first_posts,none'],
            array_values((array) $this->connection->fetchAssociative(
                "SELECT value_type, options FROM permissions WHERE permission_key = 'forum.post.approval'",
            )),
        );
    }

    public function testChangedRetiredAndRevivedDefinitions(): void
    {
        (new SchemaInstaller(fn(): Connection => $this->connection, MigrationRegistry::core()))->run();
        $sync = new PermissionCatalogSync($this->connection);
        $sync->sync(new PermissionCatalog([
            PermissionDefinition::bool('forum.view'),
            PermissionDefinition::int('forum.post.edit_window_minutes', 0, 60),
        ]));

        $changed = $sync->sync(new PermissionCatalog([
            PermissionDefinition::bool('forum.view'),
            PermissionDefinition::int('forum.post.edit_window_minutes', 0, 120),
        ]));
        $retired = $sync->sync(new PermissionCatalog([PermissionDefinition::bool('forum.view')]));
        $retiredAgain = $sync->sync(new PermissionCatalog([PermissionDefinition::bool('forum.view')]));
        $revived = $sync->sync(new PermissionCatalog([
            PermissionDefinition::bool('forum.view'),
            PermissionDefinition::int('forum.post.edit_window_minutes', 0, 120),
        ]));

        self::assertSame(['added' => 0, 'changed' => 1, 'retired' => 0], $changed);
        self::assertSame(['added' => 0, 'changed' => 0, 'retired' => 1], $retired);
        self::assertSame(['added' => 0, 'changed' => 0, 'retired' => 0], $retiredAgain);
        self::assertSame(['added' => 0, 'changed' => 1, 'retired' => 0], $revived);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM permissions'));
        self::assertNull($this->connection->fetchOne(
            "SELECT retired_at FROM permissions WHERE permission_key = 'forum.post.edit_window_minutes'",
        ));
    }
}
