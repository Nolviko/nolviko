<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Users;

use DateTimeImmutable;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Nolviko\Tests\Support\MemoryLoginAttempts;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * M02.04a: thresholds, progressive delay (never a lock), window reset, IPv6 /64 grouping, proxy spoofing.
 */
final class LoginThrottleTest extends TestCase
{
    private MemoryLoginAttempts $attempts;

    private DateTimeImmutable $now;

    private int $random = 2;

    protected function setUp(): void
    {
        $this->attempts = new MemoryLoginAttempts();
        $this->now = new DateTimeImmutable('2026-10-31 12:00:00');
    }

    public function testAccountGetsAGrowingDelayAfterFiveFailures(): void
    {
        $throttle = $this->throttle();
        $waits = [];

        for ($i = 1; $i <= 9; ++$i) {
            $throttle->record('Deniz', '198.51.100.' . $i, false);
            $waits[] = $throttle->retryAfter('DENIZ', '203.0.113.1');
        }

        self::assertSame([0, 0, 0, 0, 30, 60, 120, 240, 300], $waits, 'per account, whatever the IP; capped');

        $this->now = $this->now->modify('+300 seconds');
        self::assertSame(0, $throttle->retryAfter('deniz', '203.0.113.1'), 'a delay, never a permanent lock');
    }

    public function testWindowResetAndSuccessClearTheAccount(): void
    {
        $throttle = $this->throttle();

        for ($i = 0; $i < 5; ++$i) {
            $throttle->record('deniz', '198.51.100.1', false);
        }

        $this->now = $this->now->modify('+' . (LoginThrottle::WINDOW_SECONDS + 1) . ' seconds');
        self::assertSame(0, $throttle->retryAfter('deniz', null));

        for ($i = 0; $i < 4; ++$i) {
            $throttle->record('deniz', '198.51.100.1', false);
        }

        $throttle->record('deniz', '198.51.100.1', true, 1);
        $throttle->record('deniz', '198.51.100.1', false);
        self::assertSame(0, $throttle->retryAfter('deniz', null), 'failures before a success no longer count');
    }

    public function testUnknownNamesAreThrottledLikeRealOnes(): void
    {
        $throttle = $this->throttle();

        for ($i = 0; $i < 5; ++$i) {
            $throttle->record('no-such-user@example.com', '198.51.100.1', false);
        }

        self::assertSame(30, $throttle->retryAfter('No-Such-User@Example.com', '192.0.2.9'));
    }

    public function testTwentyFailuresBlockTheNetworkForTheWindow(): void
    {
        $throttle = $this->throttle();

        for ($i = 0; $i < 20; ++$i) {
            $throttle->record('user' . $i, '2001:db8:1:2:aaaa::' . dechex($i + 1), false);
            $this->now = $this->now->modify('+1 second');
        }

        self::assertSame(LoginThrottle::WINDOW_SECONDS - 20, $throttle->retryAfter('fresh', '2001:db8:1:2:ffff::1'));
        self::assertSame(0, $throttle->retryAfter('fresh', '2001:db8:1:3::1'), 'another /64 is another network');
    }

    public function testNetworkKeys(): void
    {
        self::assertSame(LoginThrottle::network('2001:db8::1'), LoginThrottle::network('2001:db8:0:0:ffff::9'));
        self::assertNotSame(LoginThrottle::network('2001:db8::1'), LoginThrottle::network('2001:db8:0:1::1'));
        self::assertSame(LoginThrottle::network('192.0.2.7'), LoginThrottle::network('::ffff:192.0.2.7'));
        self::assertSame(4, strlen((string) LoginThrottle::network('192.0.2.7')));
        self::assertNull(LoginThrottle::network('not-an-ip'));
        self::assertNull(LoginThrottle::network(null));
    }

    public function testSpoofedForwardedForFromAnUntrustedClientIsIgnored(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        $throttle = $this->throttle();

        for ($i = 0; $i < 20; ++$i) {
            $request = Request::create('/login', 'POST', server: [
                'REMOTE_ADDR' => '198.51.100.66',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.' . $i,
            ]);
            $throttle->record('user' . $i, $request->getClientIp(), false);
        }

        self::assertGreaterThan(0, $throttle->retryAfter('fresh', '198.51.100.66'));

        Request::setTrustedProxies(['198.51.100.66'], Request::HEADER_X_FORWARDED_FOR);
        $proxied = Request::create('/login', server: [
            'REMOTE_ADDR' => '198.51.100.66',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.1',
        ]);
        self::assertSame('192.0.2.1', $proxied->getClientIp(), 'a configured proxy may name the client');
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    public function testOldRowsArePrunedOccasionally(): void
    {
        $throttle = $this->throttle();
        $throttle->record('a', '192.0.2.1', false);
        $this->now = $this->now->modify('+1 day');
        $this->random = 1;
        $throttle->record('b', '192.0.2.1', false);

        self::assertSame(1, $this->attempts->prunes);
        self::assertCount(1, $this->attempts->rows);
    }

    private function throttle(): LoginThrottle
    {
        return new LoginThrottle(
            $this->attempts,
            fn(): DateTimeImmutable => $this->now,
            fn(): int => $this->random,
        );
    }
}
