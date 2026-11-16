<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Core;

use Nolviko\Core\RateLimit\ClientNetwork;
use PHPUnit\Framework\TestCase;

/**
 * M03.15: client networks for rate limits are pseudonymous and group what belongs together.
 */
final class ClientNetworkTest extends TestCase
{
    public function testGroupingAndHashing(): void
    {
        $v4 = ClientNetwork::key('192.0.2.10');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $v4, 'hashed, no address stored');
        self::assertSame($v4, ClientNetwork::key('::ffff:192.0.2.10'), 'IPv4-mapped IPv6 is the IPv4 address');
        self::assertNotSame($v4, ClientNetwork::key('192.0.2.11'), 'IPv4 addresses are not grouped');
        self::assertSame(ClientNetwork::key('2001:db8:1:2::1'), ClientNetwork::key('2001:db8:1:2:ffff::9'), 'one /64');
        self::assertNotSame(ClientNetwork::key('2001:db8:1:2::1'), ClientNetwork::key('2001:db8:1:3::1'));
    }

    public function testUnknownAddressesGiveNoKey(): void
    {
        self::assertNull(ClientNetwork::key(null));
        self::assertNull(ClientNetwork::key(''));
        self::assertNull(ClientNetwork::key('not an address'));
        self::assertNull(ClientNetwork::key('192.0.2.300'));
    }
}
