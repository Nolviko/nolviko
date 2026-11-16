<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Container;

use Closure;
use Nolviko\Core\Container\ContainerBuilder;
use Nolviko\Core\Container\ContainerException;
use Nolviko\Core\Container\Lazy;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ContainerTest extends TestCase
{
    public function testServicesAreLazyMemoizedAndGetDependenciesInOrder(): void
    {
        $builds = 0;
        $services = new ContainerBuilder();
        $services->value('greeting', 'hello');
        $services->value('name', 'forum');
        $services->factory('message', static function (string $greeting, string $name) use (&$builds): string {
            ++$builds;

            return $greeting . ' ' . $name;
        }, ['greeting', 'name']);
        $container = $services->build();

        self::assertSame(0, $builds);
        self::assertFalse($container->initialized('message'));
        self::assertTrue($container->initialized('greeting'));
        self::assertSame('hello forum', $container->get('message'));
        self::assertSame('hello forum', $container->get('message'));
        self::assertSame(1, $builds);
        self::assertTrue($container->initialized('message'));
    }

    public function testMissingDependencyFailsAtBuildWithDiagnosticId(): void
    {
        $services = new ContainerBuilder();
        $services->factory('kernel', static fn(mixed $router): stdClass => new stdClass(), ['router']);
        $services->factory('handler', static fn(Closure $db): stdClass => new stdClass(), [new Lazy('db')]);

        self::assertBuildFails(
            $services,
            ['container_service_missing:kernel:router', 'container_service_missing:handler:db'],
        );
    }

    public function testCircularDependenciesFailAtBuildOncePerCycle(): void
    {
        $services = new ContainerBuilder();
        $services->factory('a', static fn(mixed $b): stdClass => new stdClass(), ['b']);
        $services->factory('b', static fn(mixed $c): stdClass => new stdClass(), ['c']);
        $services->factory('c', static fn(mixed $a): stdClass => new stdClass(), ['a']);
        $services->factory('self', static fn(mixed $self): stdClass => new stdClass(), ['self']);

        self::assertBuildFails(
            $services,
            ['container_circular_dependency:a>b>c>a', 'container_circular_dependency:self>self'],
        );
    }

    public function testDuplicateIdFailsAtBuild(): void
    {
        $services = new ContainerBuilder();
        $services->value('router', 1);
        $services->factory('router', static fn(): int => 2);

        self::assertBuildFails($services, ['container_service_duplicate:router']);
    }

    public function testLazyDependencyBreaksAConstructionCycle(): void
    {
        $services = new ContainerBuilder();
        $services->factory('parent', static function (stdClass $child): stdClass {
            $parent = new stdClass();
            $parent->child = $child;

            return $parent;
        }, ['child']);
        $services->factory('child', static function (Closure $parent): stdClass {
            $child = new stdClass();
            $child->parent = $parent;

            return $child;
        }, [new Lazy('parent')]);
        $container = $services->build();

        $parent = $container->get('parent');

        self::assertSame($parent, ($parent->child->parent)());
    }

    public function testCycleReachedThroughLazyDuringConstructionFailsWithDiagnostic(): void
    {
        $services = new ContainerBuilder();
        $services->factory('a', static fn(Closure $b): mixed => $b(), [new Lazy('b')]);
        $services->factory('b', static fn(mixed $a): stdClass => new stdClass(), ['a']);
        $container = $services->build();

        try {
            $container->get('a');
            self::fail('expected a circular dependency');
        } catch (ContainerException $exception) {
            self::assertSame(['container_circular_dependency:a>b>a'], $exception->diagnostics());
        }

        self::assertFalse($container->initialized('a'));
    }

    public function testUnknownIdAndTypeMismatchHaveDiagnosticIds(): void
    {
        $services = new ContainerBuilder();
        $services->value(stdClass::class, 'not an object');
        $container = $services->build();

        foreach (
            [
                'container_service_missing:nope' => static fn() => $container->get('nope'),
                'container_service_type_mismatch:stdClass' => static fn() => $container->service(stdClass::class),
            ] as $diagnostic => $call
        ) {
            try {
                $call();
                self::fail('expected ' . $diagnostic);
            } catch (ContainerException $exception) {
                self::assertSame([$diagnostic], $exception->diagnostics());
            }
        }
    }

    /**
     * @param list<string> $expected
     */
    private static function assertBuildFails(ContainerBuilder $services, array $expected): void
    {
        try {
            $services->build();
            self::fail('expected the build to fail');
        } catch (ContainerException $exception) {
            self::assertEqualsCanonicalizing($expected, $exception->diagnostics());
            self::assertSame(implode(',', $exception->diagnostics()), $exception->getMessage());
        }
    }
}
