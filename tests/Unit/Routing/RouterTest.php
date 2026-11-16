<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Routing;

use InvalidArgumentException;
use Nolviko\Core\Routing\CanonicalRedirectException;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\MalformedPathException;
use Nolviko\Core\Routing\PathPolicy;
use Nolviko\Core\Routing\Router;
use Nolviko\Tests\Fixtures\ForumRoutes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

final class RouterTest extends TestCase
{
    public function testNamedThreadUrlIsGenerated(): void
    {
        self::assertSame('/threads/42', self::router()->generate('thread.show', ['id' => 42]));
        self::assertSame('/threads/42?unread=1', self::router()->generate('thread.show', ['id' => 42, 'unread' => 1]));
    }

    /**
     * @return list<array{0: string, 1: array<string, string>}>
     */
    public static function generatedRoutes(): array
    {
        return [
            ['home', []],
            ['thread.show', ['id' => '1']],
            ['thread.show', ['id' => '999999999999999999']],
            ['thread.page', ['id' => '42', 'page' => '7']],
            ['forum.show', ['id' => '3']],
            ['member.show', ['slug' => 'caner_e-2']],
            ['search', []],
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[DataProvider('generatedRoutes')]
    public function testGeneratedUrlsRoundTripThroughTheMatcher(string $name, array $parameters): void
    {
        $router = self::router();
        $url = $router->generate($name, $parameters);

        self::assertEquals(['_route' => $name, ...$parameters], $router->match(Request::create($url)));
    }

    public function testGenerationIncludesTheInstallationBasePath(): void
    {
        $router = self::router();
        $router->match(self::subfolderRequest('/forum/threads/42'));

        self::assertSame('/forum/threads/7', $router->generate('thread.show', ['id' => 7]));
    }

    public function testGenerationRejectsValuesThatBreakRequirements(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::router()->generate('thread.show', ['id' => 'abc']);
    }

    public function testUnknownRouteNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::router()->generate('thread.missing');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function malformedIds(): array
    {
        return [['/threads/0'], ['/threads/-1'], ['/threads/abc'], ['/threads/007'], ['/threads/4.2'],
            ['/threads/1234567890123456789'], ['/threads/42/page-0'], ['/members/-x'], ['/threads/%34%32x']];
    }

    #[DataProvider('malformedIds')]
    public function testMalformedIdsAreNotFound(string $path): void
    {
        $this->expectException(ResourceNotFoundException::class);

        self::router()->match(Request::create($path));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function malformedPaths(): array
    {
        return [['/threads/%2f'], ['/threads/%2F42'], ['/threads/%5C'], ['/threads/%00'], ['/threads/%2542'],
            ['/threads/%ZZ'], ['/threads/%C0%AF'], ['/threads/../42'], ['/threads/%2e%2e/42'], ['/threads/./42'],
            ['/threads//42'], ['http://localhost//evil.example/'], ['/members/a%0Db'], ['/members/a%20b'],
            ['/' . str_repeat('x', PathPolicy::MAX_LENGTH)]];
    }

    #[DataProvider('malformedPaths')]
    public function testMalformedPathsAreRejected(string $path): void
    {
        $this->expectException(MalformedPathException::class);

        self::router()->match(Request::create($path));
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function nonCanonicalPaths(): array
    {
        return [
            ['GET', '/threads/42/', '/threads/42'],
            ['GET', '/Threads/42', '/threads/42'],
            ['HEAD', '/SEARCH/', '/search'],
            ['GET', '/THREADS/42/?b=2&a=1', '/threads/42?a=1&b=2'],
        ];
    }

    #[DataProvider('nonCanonicalPaths')]
    public function testNonCanonicalGetRedirectsToTheOneCanonicalForm(string $method, string $path, string $to): void
    {
        try {
            self::router()->match(Request::create($path, $method));
            self::fail('expected a canonical redirect');
        } catch (CanonicalRedirectException $redirect) {
            self::assertSame($to, $redirect->location);
        }
    }

    public function testCanonicalRedirectKeepsTheBasePath(): void
    {
        try {
            self::router()->match(self::subfolderRequest('/forum/Threads/42/'));
            self::fail('expected a canonical redirect');
        } catch (CanonicalRedirectException $redirect) {
            self::assertSame('/forum/threads/42', $redirect->location);
        }
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function notRedirected(): array
    {
        return [['POST', '/threads/42/reply/'], ['POST', '/Threads/42/reply'], ['GET', '/missing/'],
            ['GET', '/threads/42/reply/']];
    }

    #[DataProvider('notRedirected')]
    public function testOnlyMatchingGetOrHeadRequestsAreRedirected(string $method, string $path): void
    {
        $this->expectException(ResourceNotFoundException::class);

        self::router()->match(Request::create($path, $method));
    }

    public function testWrongMethodReportsAllowedMethods(): void
    {
        try {
            self::router()->match(Request::create('/threads/42', 'DELETE'));
            self::fail('expected method not allowed');
        } catch (MethodNotAllowedException $exception) {
            self::assertEqualsCanonicalizing(['GET', 'HEAD'], $exception->getAllowedMethods());
        }
    }

    public function testHeadMatchesGetRoute(): void
    {
        self::assertSame('thread.show', self::router()->match(Request::create('/threads/42', 'HEAD'))['_route']);
    }

    private static function router(): Router
    {
        return new Router(CompiledRoutes::compile(ForumRoutes::definitions()));
    }

    private static function subfolderRequest(string $uri): Request
    {
        return Request::create($uri, server: [
            'SCRIPT_NAME' => '/forum/index.php',
            'SCRIPT_FILENAME' => '/var/www/forum/index.php',
            'PHP_SELF' => '/forum/index.php',
        ]);
    }
}
