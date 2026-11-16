<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Http\FrontController;
use Nolviko\Tests\Support\SiteFixture;
use Nolviko\Tests\Unit\Http\SecurityHeadersTest;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M01.07 through the real front controller: every outcome carries the policy; HSTS and proxies follow config.
 */
final class SecurityHeadersContractTest extends TestCase
{
    private SiteFixture $site;

    protected function setUp(): void
    {
        $this->site = SiteFixture::installed();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
        Request::setTrustedProxies([], FrontController::FORWARDED_HEADERS);
    }

    public function testEveryOutcomeCarriesThePolicyWithAFreshNonce(): void
    {
        $notConfigured = SiteFixture::notConfigured();
        $broken = SiteFixture::installed();
        $broken->writeConfig('<?php return 1;');

        try {
            $outcomes = [
                200 => $this->site->respond(Request::create('/robots.txt')),
                301 => $this->site->respond(Request::create('/Install/')),
                302 => $notConfigured->respond(Request::create('/')),
                400 => $this->site->respond(Request::create('/%2e%2e/config')),
                404 => $this->site->respond(Request::create('/missing')),
                405 => $this->site->respond(Request::create('/', 'POST')),
                500 => $this->site->respond(static fn(): Request => throw new RuntimeException('boom')),
                503 => $broken->respond(Request::create('/')),
            ];
        } finally {
            $notConfigured->remove();
            $broken->remove();
        }

        $nonces = [];

        foreach ($outcomes as $status => $response) {
            self::assertSame($status, $response->getStatusCode());
            $nonce = self::nonce($response);
            SecurityHeadersTest::assertPolicy($response, new CspNonce($nonce));
            $nonces[] = $nonce;
        }

        self::assertCount(count($outcomes), array_unique($nonces));
    }

    public function testRuntimePolicyFailureBeforeConfigStillCarriesThePolicy(): void
    {
        $site = SiteFixture::notConfigured();

        try {
            $response = $site->respond(
                Request::create('/robots.txt'),
                environment: [FrontController::DRIVER_ENVIRONMENT => 'unsupported'],
            );
        } finally {
            $site->remove();
        }

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        SecurityHeadersTest::assertPolicy($response, new CspNonce(self::nonce($response)));
    }

    public function testHstsFollowsConfigAndHttps(): void
    {
        $on = ['FORUM_HSTS' => 'on'];

        self::assertFalse(self::hsts($this->site->respond(Request::create('https://forum.test/'))));
        self::assertFalse(self::hsts($this->site->respond(Request::create('http://forum.test/'), environment: $on)));
        self::assertTrue(self::hsts($this->site->respond(Request::create('https://forum.test/'), environment: $on)));
        self::assertTrue(self::hsts(
            $this->site->respond(Request::create('https://forum.test/missing'), environment: $on),
        ));
    }

    public function testForwardedHeadersCountOnlyFromConfiguredProxies(): void
    {
        $request = null;
        $proxied = static function () use (&$request): Request {
            $request = Request::create('http://forum.test/', server: [
                'REMOTE_ADDR' => '10.0.0.5',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
            ]);

            return $request;
        };
        $hsts = ['FORUM_HSTS' => '1'];

        self::assertFalse(self::hsts($this->site->respond($proxied, environment: $hsts)), 'default trusts none');
        self::assertSame('10.0.0.5', $request?->getClientIp());

        $trusted = [...$hsts, 'FORUM_TRUSTED_PROXIES' => '192.0.2.1, 10.0.0.0/8'];

        self::assertTrue(self::hsts($this->site->respond($proxied, environment: $trusted)));
        self::assertSame('203.0.113.9', $request?->getClientIp());

        $other = [...$hsts, 'FORUM_TRUSTED_PROXIES' => '192.0.2.1'];

        self::assertFalse(self::hsts($this->site->respond($proxied, environment: $other)));
        self::assertSame('10.0.0.5', $request?->getClientIp());
    }

    public function testTrustIsResetWhenConfigurationFails(): void
    {
        $this->site->respond(Request::create('/robots.txt'), environment: ['FORUM_TRUSTED_PROXIES' => '10.0.0.0/8']);
        self::assertSame(['10.0.0.0/8'], Request::getTrustedProxies());

        $this->site->writeConfig('<?php return 1;');
        $this->site->respond(Request::create('/robots.txt'), environment: ['FORUM_TRUSTED_PROXIES' => '10.0.0.0/8']);

        self::assertSame([], Request::getTrustedProxies());
    }

    private static function nonce(Response $response): string
    {
        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $csp, $match), $csp);

        return $match[1];
    }

    private static function hsts(Response $response): bool
    {
        return $response->headers->has('Strict-Transport-Security');
    }
}
