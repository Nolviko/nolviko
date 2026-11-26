<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Http;

use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Http\SecurityHeaders;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M01.07 header policy per response type, the nonce and the HSTS conditions.
 */
final class SecurityHeadersTest extends TestCase
{
    /**
     * @return array<string, array{0: Response}>
     */
    public static function responses(): array
    {
        return [
            'html' => [new Response('<p>x</p>', Response::HTTP_OK, ['Content-Type' => 'text/html'])],
            'json' => [new JsonResponse(['ok' => true])],
            'redirect' => [new Response('', Response::HTTP_MOVED_PERMANENTLY, ['Location' => '/a'])],
            'error' => [new Response('<h1>500</h1>', Response::HTTP_INTERNAL_SERVER_ERROR)],
        ];
    }

    #[DataProvider('responses')]
    public function testEveryResponseTypeGetsThePolicy(Response $response): void
    {
        $nonce = CspNonce::generate();

        (new SecurityHeaders($nonce))->apply($response, Request::create('/'));

        self::assertPolicy($response, $nonce);
    }

    public function testPolicyHoldsWithoutARequest(): void
    {
        $nonce = CspNonce::generate();
        $response = (new SecurityHeaders($nonce, true))->apply(new Response(), null);

        self::assertPolicy($response, $nonce);
        self::assertFalse($response->headers->has('Strict-Transport-Security'));
    }

    public function testAPageMayAskForNoReferrerButNothingWeaker(): void
    {
        $headers = new SecurityHeaders(CspNonce::generate());
        $strict = $headers->apply(new Response('', 200, ['Referrer-Policy' => 'no-referrer']), Request::create('/'));
        $weak = $headers->apply(new Response('', 200, ['Referrer-Policy' => 'unsafe-url']), Request::create('/'));

        self::assertSame('no-referrer', $strict->headers->get('Referrer-Policy'));
        self::assertSame('strict-origin-when-cross-origin', $weak->headers->get('Referrer-Policy'));
    }

    public function testNonceIsRandomAndHeaderSafe(): void
    {
        $nonces = array_map(static fn(): string => CspNonce::generate()->value, range(1, 20));

        self::assertCount(20, array_unique($nonces));

        foreach ($nonces as $nonce) {
            self::assertMatchesRegularExpression('/^[A-Za-z0-9+\/]{22}==$/D', $nonce);
        }
    }

    /**
     * @return array<string, array{0: bool, 1: string, 2: bool}>
     */
    public static function hstsCases(): array
    {
        return [
            'enabled over https' => [true, 'https://forum.test/', true],
            'enabled over http' => [true, 'http://forum.test/', false],
            'disabled over https' => [false, 'https://forum.test/', false],
        ];
    }

    #[DataProvider('hstsCases')]
    public function testHstsOnlyWhenEnabledAndHttps(bool $enabled, string $uri, bool $expected): void
    {
        $response = (new SecurityHeaders(CspNonce::generate(), $enabled))->apply(new Response(), Request::create($uri));

        self::assertSame($expected, $response->headers->has('Strict-Transport-Security'));

        if ($expected) {
            self::assertSame(
                'max-age=' . SecurityHeaders::HSTS_MAX_AGE,
                $response->headers->get('Strict-Transport-Security'),
            );
        }
    }

    public function testExtensionHostsAreAddedButNeverKeywords(): void
    {
        $nonce = CspNonce::generate();
        $nonce->allow('script-src', 'https://*.googletagmanager.com');
        $nonce->allow('connect-src', 'https://region1.google-analytics.com');
        $nonce->allow('script-src', "'unsafe-inline'");
        $nonce->allow('script-src', 'http://example.com');
        $nonce->allow('script-src', '*');
        $nonce->allow('object-src', 'https://example.com');
        $nonce->allow('frame-src', 'https://challenges.cloudflare.com');

        $csp = (new SecurityHeaders($nonce))->contentSecurityPolicy();

        self::assertStringContainsString("'nonce-" . $nonce->value . "' https://*.googletagmanager.com;", $csp);
        self::assertStringContainsString("connect-src 'self' https://region1.google-analytics.com;", $csp);
        self::assertStringContainsString('player.vimeo.com https://challenges.cloudflare.com;', $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('http://example.com', $csp);
        self::assertStringContainsString("object-src 'none';", $csp);
        self::assertFalse(CspNonce::validSource('script-src', 'https://exa mple.com'));
    }

    public static function assertPolicy(Response $response, CspNonce $nonce): void
    {
        $headers = $response->headers;
        $csp = (string) $headers->get('Content-Security-Policy');

        self::assertCount(1, $headers->all('content-security-policy'));
        self::assertStringContainsString("script-src 'self' 'nonce-" . $nonce->value . "'", $csp);
        self::assertStringContainsString("frame-ancestors 'none'", $csp);
        self::assertStringContainsString('frame-src https://www.youtube-nocookie.com https://player.vimeo.com', $csp);
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString('unsafe-eval', $csp);
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertStringContainsString('camera=()', (string) $headers->get('Permissions-Policy'));
    }
}
