<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Seo;

use Nolviko\Core\Http\SeoMetadata;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\RouterFactory;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Seo\Http\SeoHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SeoPolicyTest extends TestCase
{
    #[DataProvider('invalidOrigins')]
    public function testUnconfiguredOrUnsafeOriginNeverUsesRequestHost(?string $origin): void
    {
        $site = new SiteUrl($origin);
        self::assertFalse($site->configured());
        self::assertNull($site->absolute('/forums'));
    }

    public static function invalidOrigins(): array
    {
        return [[null], [''], ['//example.test'], ['javascript:alert(1)'], ['https://user@example.test'],
            ['https://user:pass@example.test'], ['https://example.test/?secret=1'],
            ["https://example.test/\r\nHeader:x"], ['https://example.test/../private'],
            ['https://example.test/%2fprivate'], ['https://example.test/#fragment']];
    }

    public function testBasePathAndPortArePreservedExactlyOnce(): void
    {
        $site = new SiteUrl('https://forum.example.test:8443/board/');
        self::assertTrue($site->configured());
        self::assertSame(
            'https://forum.example.test:8443/board/threads/42',
            $site->absolute('/threads/42')
        );
        self::assertSame(
            'https://forum.example.test:8443/board/threads/42',
            $site->absolute('/board/threads/42')
        );
        self::assertNull($site->absolute('//attacker.test/path'));
        self::assertNull($site->absolute("/threads/42\r\nX:x"));
    }

    public function testCanonicalIgnoresTrackingQueriesAndPrivateRequestsGetNoindex(): void
    {
        $urls = RouterFactory::create(dirname(__DIR__, 3), '/nonexistent-seo-test-storage');
        $seo = new SeoMetadata(new SiteUrl('https://canonical.example.test/board'));
        $seo->bind(['_route' => CoreRoutes::THREAD_PAGE, 'slug' => 'konu', 'thread' => '42', 'page' => '2',
            'url' => 'https://attacker.test/', 'token' => 'private-sentinel'], $urls, false);
        $response = $seo->apply(new Response('', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
        self::assertSame(
            'https://canonical.example.test/board/threads/konu-42/page-2',
            $seo->view()['canonical']
        );
        self::assertStringNotContainsString('attacker', (string) $response->headers->get('Link'));
        self::assertStringNotContainsString('sentinel', (string) $response->headers->get('Link'));
        self::assertFalse($response->headers->has('X-Robots-Tag'));
        $seo->bind(['_route' => CoreRoutes::THREAD_SHOW, 'slug' => 'konu', 'thread' => '42'], $urls, true);
        self::assertStringContainsString(
            'noindex',
            (string) $seo->apply(new Response())->headers->get('X-Robots-Tag')
        );
        $seo->bind(['_route' => CoreRoutes::ACCOUNT], $urls, false);
        self::assertNull($seo->view()['canonical']);
        self::assertTrue($seo->view()['noindex']);
        $seo->reset();
        self::assertNull($seo->view()['canonical']);
    }

    public function testRobotsNeedsNoDatabaseOrExternalFetchAndNeverReflectsHost(): void
    {
        $urls = RouterFactory::create(dirname(__DIR__, 3), '/nonexistent-seo-test-storage');
        $handler = new SeoHandler(
            new SiteUrl('https://canonical.example.test/board'),
            $urls,
            static function (): never {
                self::fail('robots connected to the database');
            }
        );
        $response = $handler->handle(Request::create('https://attacker.test/robots.txt'), ['_route'
            => CoreRoutes::ROBOTS]);
        self::assertStringContainsString('Disallow: /board/admin', (string) $response->getContent());
        self::assertStringContainsString(
            'Sitemap: https://canonical.example.test/board/sitemap.xml',
            (string) $response->getContent()
        );
        self::assertStringNotContainsString('attacker', (string) $response->getContent());
    }
}
