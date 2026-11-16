<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use LogicException;
use Nolviko\Core\Application\CompositionRoot;
use Nolviko\Core\Application\CoreServiceProvider;
use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Container\Container;
use Nolviko\Core\Container\ContainerBuilder;
use Nolviko\Core\Container\ContainerException;
use Nolviko\Core\Container\ServiceProvider;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Http\Kernel;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\Router;
use Nolviko\Modules\Forums\Http\ForumIndexHandler;
use Nolviko\Tests\Support\RecordingReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M01.04 contract: the real core graph is valid, lazy, and never connects to the database unless asked to.
 */
final class CompositionRootTest extends TestCase
{
    public function testCoreGraphBuildsWithNothingInstantiated(): void
    {
        $container = self::container();

        $services = [Kernel::class, Router::class, MessageTranslator::class, ForumIndexHandler::class,
            Connection::class];

        foreach ($services as $id) {
            self::assertFalse($container->initialized($id), $id);
        }
    }

    public function testRouteWithoutDatabaseNeverOpensAConnection(): void
    {
        $container = self::container();

        // The board index (home) reads the database; robots.txt is a route that never does.
        $response = $container->service(Kernel::class)->handle(Request::create('/robots.txt'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertFalse($container->initialized(Connection::class), 'no route needed the connection service');
        self::assertFalse($container->service(Connection::class)->isConnected(), 'building it must not connect');
    }

    public function testOnlyTheMatchedRouteHandlerIsBuilt(): void
    {
        $container = self::container();

        $response = $container->service(Kernel::class)->handle(Request::create('/missing'));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertFalse($container->initialized(ForumIndexHandler::class));
    }

    public function testExtensionPointLoadsOnlyTheCoreProvider(): void
    {
        self::assertEquals([new CoreServiceProvider()], CompositionRoot::providers());
    }

    public function testProviderRedefiningACoreServiceFailsAtBoot(): void
    {
        $override = new class implements ServiceProvider {
            public function register(ContainerBuilder $services): void
            {
                $services->factory(Router::class, static fn(): object => new \stdClass());
            }
        };

        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('container_service_duplicate:' . Router::class);

        CompositionRoot::build(
            self::root(),
            self::root() . '/missing-storage',
            self::config(),
            InstallationState::Installed,
            new RecordingReporter(),
            providers: [...CompositionRoot::providers(), $override],
        );
    }

    public function testConnectionFactoryMapsThePortableDrivers(): void
    {
        foreach (['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql'] as $name => $driver) {
            $connection = ConnectionFactory::create(self::config($name));

            self::assertSame($driver, $connection->getParams()['driver']);
            self::assertSame(3307, $connection->getParams()['port']);
            self::assertFalse($connection->isConnected());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('database_driver_unsupported');

        ConnectionFactory::create(self::config('sqlite'));
    }

    public function testBeforeInstallationTheConnectionCannotBeBuilt(): void
    {
        $container = CompositionRoot::build(
            self::root(),
            self::root() . '/missing-storage',
            null,
            InstallationState::NotConfigured,
            new RecordingReporter(),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('config_missing');

        $container->get(Connection::class);
    }

    private static function container(): Container
    {
        return CompositionRoot::build(
            self::root(),
            self::root() . '/missing-storage',
            self::config(),
            InstallationState::Installed,
            new RecordingReporter(),
        );
    }

    private static function config(string $driver = 'mysql'): Config
    {
        $secret = new Secret(str_repeat('s', 32));

        return new Config($driver, '192.0.2.1', 3307, 'forum', 'forum', new Secret('p'), $secret);
    }

    private static function root(): string
    {
        return (string) realpath(dirname(__DIR__, 2));
    }
}
