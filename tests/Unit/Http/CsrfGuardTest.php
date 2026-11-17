<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Http;

use Nolviko\Core\Http\CsrfGuard;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\Kernel;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\RecordingReporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M01.11: state-changing requests need a valid token and, when the browser names one, this site's origin.
 */
final class CsrfGuardTest extends TestCase
{
    private const SITE = 'https://forum.example';

    private CsrfTokens $tokens;

    private Kernel $kernel;

    /** Handler calls; the handler must never run when the guard refuses. */
    private object $handler;

    protected function setUp(): void
    {
        $this->tokens = new CsrfTokens(new ArraySession());
        $handler = new class implements RouteHandler {
            public int $calls = 0;

            public function handle(Request $request, array $parameters): Response
            {
                ++$this->calls;

                return new Response('done');
            }
        };
        $this->handler = $handler;
        $definitions = [
            new RouteDefinition('act', '/act', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']),
            new RouteDefinition(CoreRoutes::LOGOUT, '/logout', ['GET', 'POST']),
        ];
        $this->kernel = new Kernel(
            new Router(CompiledRoutes::compile($definitions)),
            [
                'act' => static fn(): RouteHandler => $handler,
                CoreRoutes::LOGOUT => static fn(): RouteHandler => $handler,
            ],
            new ErrorResponder(null),
            new RecordingReporter(),
            [new CsrfGuard($this->tokens, new ErrorResponder(null))],
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeMethods(): array
    {
        return ['POST' => ['POST'], 'PUT' => ['PUT'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE']];
    }

    public function testAnExpiredLogoutFormLeadsToTheConfirmationInsteadOfA403(): void
    {
        $response = $this->kernel->handle(Request::create('/logout', 'POST', ['_csrf_token' => 'expired']));

        // 2026-11-14: back to GET /logout (a fresh form); the handler did not run, nobody was logged out.
        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame('/logout', $response->headers->get('Location'));
        self::assertSame(0, $this->handler->calls);
    }

    #[DataProvider('unsafeMethods')]
    public function testUnsafeMethodWithoutValidTokenIs403(string $method): void
    {
        foreach ([null, 'forged', (new CsrfTokens(new ArraySession()))->token()] as $token) {
            $headers = $token === null ? [] : ['HTTP_X_CSRF_TOKEN' => $token];
            $response = $this->send($method, server: $headers);

            self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }

        self::assertSame(0, $this->handler->calls, 'the handler never runs');
    }

    #[DataProvider('unsafeMethods')]
    public function testHeaderTokenIsAccepted(string $method): void
    {
        $response = $this->send($method, server: ['HTTP_X_CSRF_TOKEN' => $this->tokens->token()]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(1, $this->handler->calls);
    }

    public function testFormFieldTokenIsAcceptedAndArrayValueIsRefusedWithout500(): void
    {
        $valid = $this->send('POST', [CsrfTokens::FIELD => $this->tokens->token()]);
        $array = $this->send('POST', [CsrfTokens::FIELD => [$this->tokens->token()]]);

        self::assertSame(Response::HTTP_OK, $valid->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $array->getStatusCode());
    }

    public function testSafeMethodsNeedNoToken(): void
    {
        self::assertSame(Response::HTTP_OK, $this->send('GET')->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->send('HEAD')->getStatusCode());
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: int}>
     */
    public static function origins(): array
    {
        return [
            'same origin' => [['HTTP_ORIGIN' => self::SITE], Response::HTTP_OK],
            'other origin' => [['HTTP_ORIGIN' => 'https://evil.example'], Response::HTTP_FORBIDDEN],
            'other scheme' => [['HTTP_ORIGIN' => 'http://forum.example'], Response::HTTP_FORBIDDEN],
            'other port' => [['HTTP_ORIGIN' => self::SITE . ':8443'], Response::HTTP_FORBIDDEN],
            'opaque origin' => [['HTTP_ORIGIN' => 'null'], Response::HTTP_FORBIDDEN],
            // A page served with Referrer-Policy: no-referrer posts its own forms with Origin: null (2026-11-17).
            'opaque origin, same-origin fetch' => [
                ['HTTP_ORIGIN' => 'null', 'HTTP_SEC_FETCH_SITE' => 'same-origin'],
                Response::HTTP_OK,
            ],
            'opaque origin, cross-site (sandboxed frame)' => [
                ['HTTP_ORIGIN' => 'null', 'HTTP_SEC_FETCH_SITE' => 'cross-site'],
                Response::HTTP_FORBIDDEN,
            ],
            'opaque origin, same-site subdomain' => [
                ['HTTP_ORIGIN' => 'null', 'HTTP_SEC_FETCH_SITE' => 'same-site'],
                Response::HTTP_FORBIDDEN,
            ],
            'origin wins over referer' => [
                ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => self::SITE . '/act'],
                Response::HTTP_FORBIDDEN,
            ],
            'same-site referer' => [['HTTP_REFERER' => 'HTTPS://Forum.Example:443/thread?id=1'], Response::HTTP_OK],
            'foreign referer' => [['HTTP_REFERER' => 'https://evil.example/forum.example'], Response::HTTP_FORBIDDEN],
            'unparsable referer' => [['HTTP_REFERER' => 'not a url'], Response::HTTP_FORBIDDEN],
            'neither (stripped by privacy tools)' => [[], Response::HTTP_OK],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('origins')]
    public function testOriginAndRefererAreASecondLayer(array $headers, int $status): void
    {
        $response = $this->send('POST', server: $headers + ['HTTP_X_CSRF_TOKEN' => $this->tokens->token()]);

        self::assertSame($status, $response->getStatusCode());
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $server
     */
    private function send(string $method, array $body = [], array $server = []): Response
    {
        return $this->kernel->handle(Request::create(self::SITE . '/act', $method, $body, server: $server));
    }
}
