<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Routing;

use Nolviko\Core\Extensions\PageMeta;
use Nolviko\Core\Http\SeoMetadata;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\Routing\RoutePatterns;
use Nolviko\Core\Routing\Router;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Address patterns and page metadata filters from extensions (2026-11-15, SEO extension).
 */
final class RoutePatternsTest extends TestCase
{
    public function testAMovedThreadKeepsItsOldAddressesAsAliases(): void
    {
        $router = $this->router([
            CoreRoutes::THREAD_SHOW => ['path' => '/konu/{slug}-{thread}', 'aliases' => ['/t/{thread}']],
        ]);
        $values = ['slug' => 'merhaba', 'thread' => 5];

        self::assertSame('/konu/merhaba-5', $router->generate(CoreRoutes::THREAD_SHOW, $values));
        self::assertSame('/konu/merhaba-5/page-2', $router->generate(CoreRoutes::THREAD_PAGE, [
            'slug' => 'merhaba', 'thread' => 5, 'page' => 2,
        ]));
        self::assertSame(CoreRoutes::THREAD_SHOW, $router->match(Request::create('/konu/merhaba-5'))['_route']);
        foreach (['/threads/merhaba-5', '/t/5'] as $old) {
            self::assertSame(
                CoreRoutes::THREAD_SHOW,
                $router->match(Request::create($old))[RouteDefinition::ALIAS_ATTRIBUTE] ?? null,
                $old,
            );
        }
        self::assertSame(
            CoreRoutes::THREAD_PAGE,
            $router->match(Request::create('/threads/merhaba-5/page-3'))[RouteDefinition::ALIAS_ATTRIBUTE] ?? null,
        );
    }

    public function testAPatternWithoutTheSlugDropsIt(): void
    {
        $router = $this->router([CoreRoutes::THREAD_SHOW => ['path' => '/t/{thread}', 'aliases' => []]]);

        self::assertSame('/t/5', $router->generate(CoreRoutes::THREAD_SHOW, ['slug' => 'merhaba', 'thread' => 5]));
        self::assertSame('/t/5/page-2', $router->generate(CoreRoutes::THREAD_PAGE, [
            'slug' => 'merhaba', 'thread' => 5, 'page' => 2,
        ]));
    }

    public function testUnsafePatternsLeaveTheDefaults(): void
    {
        foreach (
            [
                [CoreRoutes::THREAD_SHOW => ['path' => '/login/{thread}', 'aliases' => []]],
                [CoreRoutes::THREAD_SHOW => ['path' => '/{thread}', 'aliases' => []]],
                [CoreRoutes::THREAD_SHOW => ['path' => '/konu/{slug}', 'aliases' => []]],
                [CoreRoutes::FORUM_SHOW => ['path' => '/f/{forum}{x}', 'aliases' => []]],
                [CoreRoutes::LOGIN => ['path' => '/giris', 'aliases' => []]],
                [CoreRoutes::FORUM_SHOW => ['path' => '/f/{forum}', 'aliases' => []],
                    CoreRoutes::TAG_SHOW => ['path' => '/f/{tag}', 'aliases' => []]],
            ] as $patterns
        ) {
            $applied = RoutePatterns::apply(CoreRoutes::definitions(), $patterns)['definitions'];
            self::assertEquals(CoreRoutes::definitions(), $applied, (string) json_encode($patterns));
        }
    }

    public function testConflictCheckAcceptsSeparateWords(): void
    {
        $built = RoutePatterns::build(CoreRoutes::definitions(), [
            CoreRoutes::THREAD_SHOW => ['path' => '/konu/{slug}-{thread}', 'aliases' => []],
            CoreRoutes::FORUM_SHOW => ['path' => '/forum/{forum}', 'aliases' => []],
        ]);

        self::assertSame([], RoutePatterns::conflicts($built['definitions'], [
            CoreRoutes::THREAD_SHOW => ['slug' => 'ornek', 'thread' => '1'],
            CoreRoutes::FORUM_SHOW => ['forum' => '2'],
        ]));
    }

    public function testExtensionFiltersChangeThePageMetadata(): void
    {
        $seo = new SeoMetadata(new SiteUrl('https://forum.example'), [
            static fn(PageMeta $meta): PageMeta => $meta->with([
                'title' => $meta->title . ' | Site', 'keywords' => 'a, b', 'image' => 'javascript:alert(1)',
                'noindex' => $meta->key === 'thread:5',
            ]),
            static fn(PageMeta $meta): PageMeta => throw new \RuntimeException('broken extension'),
        ]);
        $router = $this->router([]);
        $seo->bind(['_route' => CoreRoutes::THREAD_SHOW, 'slug' => 'merhaba', 'thread' => '5'], $router, false);
        $meta = $seo->meta('Merhaba', 'Açıklama');

        self::assertSame('Merhaba | Site', $meta['title']);
        self::assertSame('a, b', $meta['keywords']);
        self::assertNull($meta['image'], 'only http(s) images');
        self::assertTrue($meta['noindex']);
        self::assertSame('https://forum.example/threads/merhaba-5', $meta['canonical']);
        self::assertSame('noindex, nofollow, noarchive', $seo->apply(new Response('ok'))->headers->get('X-Robots-Tag'));
        $page = SeoMetadata::page(['_route' => CoreRoutes::THREAD_PAGE, 'thread' => '5']);
        self::assertSame(['thread', 'thread:5'], $page);
    }

    /** @param array<string, array{path: string, aliases: list<string>}> $patterns */
    private function router(array $patterns): Router
    {
        $built = RoutePatterns::apply(CoreRoutes::definitions(), $patterns);

        return new Router(CompiledRoutes::compile($built['definitions']), dropped: $built['dropped']);
    }
}
