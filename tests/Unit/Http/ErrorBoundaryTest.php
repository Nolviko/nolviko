<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Http;

use LogicException;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\HtmlPage;
use Nolviko\Core\Http\Kernel;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Http\SecurityHeaders;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\Routing\Router;
use Nolviko\Tests\Support\RecordingReporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Kernel and ErrorResponder edge cases that the front controller cannot reach with its real routes.
 */
final class ErrorBoundaryTest extends TestCase
{
    public function testThrowingHandlerIsReportedAndBecomes500(): void
    {
        $reporter = new RecordingReporter();
        $handler = new class implements RouteHandler {
            public function handle(Request $request, array $parameters): Response
            {
                throw new RuntimeException('handler_secret');
            }
        };

        $kernel = self::kernel(['boom' => static fn(): RouteHandler => $handler], $reporter);
        $response = $kernel->handle(Request::create('/boom'));

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame('handler_secret', current($reporter->incidents)->getMessage());
        self::assertStringNotContainsString('handler_secret', (string) $response->getContent());
    }

    public function testRouteWithoutHandlerBecomes500(): void
    {
        $reporter = new RecordingReporter();

        $response = self::kernel([], $reporter)->handle(Request::create('/boom'));

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertInstanceOf(LogicException::class, current($reporter->incidents));
    }

    public function testMalformedPathBecomes400AndNonCanonicalPathBecomes301(): void
    {
        $kernel = self::kernel([], new RecordingReporter());

        $bad = $kernel->handle(Request::create('/boom%2f'));
        $redirect = $kernel->handle(Request::create('/Boom/?a=1'));

        self::assertSame(Response::HTTP_BAD_REQUEST, $bad->getStatusCode());
        self::assertStringContainsString('<h1>400</h1>', (string) $bad->getContent());
        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $redirect->getStatusCode());
        self::assertSame('/boom?a=1', $redirect->headers->get('Location'));
        self::assertSame('', $redirect->getContent());
    }

    public function testWithoutTranslatorPagesFallBackToStatusAndRawIds(): void
    {
        $responder = new ErrorResponder(null);

        $unavailable = $responder->unavailable(['extension_missing:intl', 'memory_limit_below_128M']);
        $server = $responder->serverError('0123456789abcdef');

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $unavailable->getStatusCode());
        self::assertStringContainsString('<h1>503</h1>', (string) $unavailable->getContent());
        self::assertStringContainsString('<li>extension_missing:intl</li>', (string) $unavailable->getContent());
        self::assertStringContainsString('<li>0123456789abcdef</li>', (string) $server->getContent());
        self::assertSame('GET', $responder->methodNotAllowed(['GET'])->headers->get('Allow'));
    }

    public function testHtmlPageEscapesEveryValue(): void
    {
        $html = HtmlPage::render('en"><x', '<script>t</script>', ['a & b'], ['<i>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<i>', $html);
        self::assertStringNotContainsString('"><x', $html);
        self::assertStringContainsString('a &amp; b', $html);
    }

    public function testHtmlPageStyleIsTheOnlyInlineBlockAndTheCspAllowsExactlyIt(): void
    {
        $html = HtmlPage::render('tr', 'Sayfa bulunamadı', ['Yok.'], [], 404);
        self::assertSame(1, substr_count($html, '<style>'));
        self::assertStringContainsString('<style>' . HtmlPage::STYLE . '</style>', $html);
        self::assertStringContainsString('<p class="code">404</p>', $html);
        foreach (['url(', '@import', '</style', '\\'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, HtmlPage::STYLE);
        }
        $csp = (new SecurityHeaders(CspNonce::generate()))->contentSecurityPolicy();
        $source = "'sha256-" . base64_encode(hash('sha256', HtmlPage::STYLE, true)) . "'";
        self::assertStringContainsString($source, $csp);
        self::assertStringNotContainsString($source, explode('style-src', $csp)[0]);
    }

    public function testReportCarriesClientMethodAndPathButNoQuery(): void
    {
        $reporter = new RecordingReporter();
        $handler = new class implements RouteHandler {
            public function handle(Request $request, array $parameters): Response
            {
                throw new RuntimeException('boom');
            }
        };
        $kernel = self::kernel(['boom' => static fn(): RouteHandler => $handler], $reporter);

        $kernel->handle(Request::create('/boom?token=abc', server: ['REMOTE_ADDR' => '203.0.113.9']));

        self::assertSame(
            ['client_ip' => '203.0.113.9', 'method' => 'GET', 'path' => '/boom'],
            current($reporter->contexts),
        );
    }

    /**
     * @param array<string, Closure(): RouteHandler> $handlers
     */
    private static function kernel(array $handlers, RecordingReporter $reporter): Kernel
    {
        $router = new Router(CompiledRoutes::compile([new RouteDefinition('boom', '/boom', ['GET'])]));

        return new Kernel($router, $handlers, new ErrorResponder(null), $reporter);
    }
}
