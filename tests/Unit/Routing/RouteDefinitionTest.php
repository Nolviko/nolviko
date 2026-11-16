<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Routing;

use InvalidArgumentException;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\RouteDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteDefinitionTest extends TestCase
{
    public function testGetImpliesHead(): void
    {
        self::assertSame(['GET', 'HEAD'], (new RouteDefinition('home', '/', ['GET']))->methods);
        self::assertSame(['POST'], (new RouteDefinition('a.b', '/a', ['POST']))->methods);
    }

    /**
     * @return list<array{0: string, 1: string, 2: list<string>, 3: array<string, string>, 4: string}>
     */
    public static function invalidDefinitions(): array
    {
        $id = ['id' => '[1-9][0-9]*'];
        $upper = 'route_requirement_accepts_upper_case';

        return [
            ['Thread.show', '/threads', ['GET'], [], 'route_name_invalid'],
            ['thread..show', '/threads', ['GET'], [], 'route_name_invalid'],
            ['thread.show', '/Threads/{id}', ['GET'], $id, 'route_path_invalid'],
            ['thread.show', '/threads/{id}/', ['GET'], $id, 'route_path_invalid'],
            ['thread.show', 'threads/{id}', ['GET'], $id, 'route_path_invalid'],
            ['thread.show', '/threads//{id}', ['GET'], $id, 'route_path_invalid'],
            ['thread.show', '/threads/{id}', ['GET'], [], 'route_requirements_mismatch'],
            ['thread.show', '/threads', ['GET'], $id, 'route_requirements_mismatch'],
            ['member.show', '/members/{name}', ['GET'], ['name' => '[^/]+'], $upper],
            ['member.show', '/members/{name}', ['GET'], ['name' => '[a-zA-Z]+'], $upper],
            ['member.show', '/members/{name}', ['GET'], ['name' => '(?i)[a-z]+'], $upper],
            ['member.show', '/members/{name}', ['GET'], ['name' => '[a-z'], $upper],
            ['home', '/', [], [], 'route_methods_invalid'],
            ['home', '/', ['get'], [], 'route_methods_invalid'],
            ['home', '/', ['TRACE'], [], 'route_methods_invalid'],
        ];
    }

    /**
     * @param list<string> $methods
     * @param array<string, string> $requirements
     */
    #[DataProvider('invalidDefinitions')]
    public function testPolicyViolationsAreRejected(
        string $name,
        string $path,
        array $methods,
        array $requirements,
        string $error,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($error);

        new RouteDefinition($name, $path, $methods, $requirements);
    }

    public function testDuplicateNamesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('route_name_duplicate:home');

        CompiledRoutes::compile([
            new RouteDefinition('home', '/', ['GET']),
            new RouteDefinition('home', '/a', ['GET']),
        ]);
    }

    public function testCoreRoutesCompile(): void
    {
        $compiled = CompiledRoutes::compile(CoreRoutes::definitions());

        self::assertArrayHasKey(CoreRoutes::HOME, $compiled->generator);
    }
}
