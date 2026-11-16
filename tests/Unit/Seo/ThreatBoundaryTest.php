<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Seo;

use Nolviko\Core\Http\HttpsGuard;
use Nolviko\Core\Installer\WebExposureProbe;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Review regressions are written before the final fix phase; no live endpoint is contacted. */
final class ThreatBoundaryTest extends TestCase
{
    public function testHostPortCannotSelectAServiceForTheInstallerSelfProbe(): void
    {
        $root = new TemporaryDirectory();
        $root->write('config/.keep', '');
        $root->write('storage/.keep', '');
        $seen = [];
        try {
            $probe = new WebExposureProbe(static function (string $url, string $host) use (&$seen): array {
                $seen[] = $url;
                return [403, ''];
            });
            $probe->probe(Request::create('http://forum.example.test:3306/install', server: [
                'SERVER_ADDR' => '127.0.0.1', 'SERVER_PORT' => 3306,
            ]), $root->path, $root->path . '/storage');
            self::assertNotEmpty($seen);
            foreach ($seen as $url) {
                self::assertSame(80, parse_url($url, PHP_URL_PORT));
                self::assertSame('127.0.0.1', parse_url($url, PHP_URL_HOST));
            }
        } finally {
            $root->remove();
        }
    }

    public function testConfiguredHttpsRedirectDoesNotReflectAnAttackerHost(): void
    {
        $guard = new HttpsGuard(true, new SiteUrl('https://canonical.example.test/board'));
        $response = $guard->guard(
            [RouteDefinition::HTTPS_ATTRIBUTE => true],
            Request::create('http://attacker.test/board/admin')
        );
        self::assertSame('https://canonical.example.test/board/admin', $response->headers->get('Location'));
    }
}
