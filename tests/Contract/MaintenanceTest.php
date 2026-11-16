<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Nolviko\Core\Http\MaintenanceGuard;
use Nolviko\Core\Session\NativeSession;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Tests\Support\SiteFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Maintenance precedes identity/database resolution and every route; owner recovery alone reopens the site.
 */
final class MaintenanceTest extends TestCase
{
    public function testEveryDynamicRequestIsBlockedWithoutDatabaseOrSessionAccess(): void
    {
        $site = SiteFixture::installed();
        $marker = $site->configDirectory . '/' . MaintenanceGuard::FILE;
        file_put_contents($marker, '<?php throw new Exception("marker-secret");');
        touch($marker, 1);

        foreach (['/', '/forums', '/admin', '/install', '/unknown', '/password/reset?token=secret'] as $path) {
            foreach (['GET', 'POST', 'HEAD'] as $method) {
                $request = Request::create($path, $method, ['maintenance_bypass' => '1'], [
                    NativeSession::COOKIE => 'invalid-session',
                    AuthSession::REMEMBER_COOKIE => 'invalid-token',
                ]);
                $response = $site->respond($request);
                self::assertSame(503, $response->getStatusCode(), $method . ' ' . $path);
                self::assertSame('300', $response->headers->get('Retry-After'));
                self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
                self::assertTrue($response->headers->has('Content-Security-Policy'));
                self::assertSame([], $response->headers->getCookies());
                self::assertStringNotContainsString('marker-secret', (string) $response->getContent());
            }
        }

        self::assertFileExists($marker, 'a stale maintenance latch never expires');
        unlink($marker);
        self::assertSame(200, $site->respond(Request::create('/robots.txt'))->getStatusCode());
    }

    public function testMaintenanceCannotReopenTheInstaller(): void
    {
        $site = SiteFixture::installed();
        $marker = $site->configDirectory . '/' . MaintenanceGuard::FILE;
        mkdir($marker);
        self::assertSame(503, $site->respond(Request::create('/install'))->getStatusCode());
        rmdir($marker);
        self::assertSame(404, $site->respond(Request::create('/install'))->getStatusCode());
    }

    public function testFreshInstallCanAlsoBeHeldDuringFileReplacement(): void
    {
        $site = SiteFixture::notConfigured();
        $marker = $site->configDirectory . '/' . MaintenanceGuard::FILE;
        touch($marker);
        self::assertSame(503, $site->respond(Request::create('/install'))->getStatusCode());
        unlink($marker);
        self::assertSame(200, $site->respond(Request::create('/install'))->getStatusCode());
    }
}
