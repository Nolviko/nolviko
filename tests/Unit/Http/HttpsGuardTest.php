<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\FrontController;
use Nolviko\Core\Http\HttpsGuard;
use Nolviko\Core\Http\Kernel;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\Routing\Router;
use Nolviko\Tests\Support\RecordingReporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `http.require_https`: routes declared requiresHttps move to HTTPS; nothing else does.
 */
final class HttpsGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], FrontController::FORWARDED_HEADERS);
    }

    public function testHttpGetOnSensitiveRouteIs301ToHttpsKeepingTheQuery(): void
    {
        $response = self::kernel(true)->handle(Request::create('http://forum.test/login?next=%2Fa'));

        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $response->getStatusCode());
        self::assertSame('https://forum.test/login?next=%2Fa', $response->headers->get('Location'));
        self::assertSame('', $response->getContent());
    }

    public function testHttpPostIs308SoTheBodyIsNotTurnedIntoAGet(): void
    {
        $response = self::kernel(true)->handle(Request::create('http://forum.test/login', 'POST'));

        self::assertSame(Response::HTTP_PERMANENTLY_REDIRECT, $response->getStatusCode());
        self::assertSame('https://forum.test/login', $response->headers->get('Location'));
    }

    public function testHttpsAndOtherRoutesAndDisabledSettingPassThrough(): void
    {
        self::assertSame(200, self::kernel(true)->handle(Request::create('https://forum.test/login'))->getStatusCode());
        self::assertSame(200, self::kernel(true)->handle(Request::create('http://forum.test/'))->getStatusCode());
        self::assertSame(200, self::kernel(false)->handle(Request::create('http://forum.test/login'))->getStatusCode());
    }

    public function testForwardedProtoCountsOnlyFromATrustedProxy(): void
    {
        $proxied = static fn(): Request => Request::create(
            'http://forum.test/login',
            server: ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https'],
        );

        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, self::kernel(true)->handle($proxied())->getStatusCode());

        Request::setTrustedProxies(['10.0.0.0/8'], FrontController::FORWARDED_HEADERS);

        self::assertSame(Response::HTTP_OK, self::kernel(true)->handle($proxied())->getStatusCode());
    }

    private static function kernel(bool $requireHttps): Kernel
    {
        $router = new Router(CompiledRoutes::compile([
            new RouteDefinition('home', '/', ['GET']),
            new RouteDefinition('login', '/login', ['GET', 'POST'], requiresHttps: true),
        ]));
        $ok = new class implements RouteHandler {
            public function handle(Request $request, array $parameters): Response
            {
                return new Response('ok');
            }
        };
        $handler = static fn(): RouteHandler => $ok;

        return new Kernel(
            $router,
            ['home' => $handler, 'login' => $handler],
            new ErrorResponder(null),
            new RecordingReporter(),
            [new HttpsGuard($requireHttps)],
        );
    }
}
