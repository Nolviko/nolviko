<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Api;

use Nolviko\Modules\Api\Infrastructure\Webhooks;
use PHPUnit\Framework\TestCase;

/**
 * Webhooks connect to the address that was checked (DNS rebinding cannot swap it), while the Host header and the TLS
 * peer name stay the original host, so the certificate is verified against the name the owner entered.
 */
final class WebhookPinningTest extends TestCase
{
    public function testRequestGoesToThePinnedAddressWithTheOriginalHost(): void
    {
        self::assertSame(
            ['https://93.184.215.14/hook?a=1&b=2', 'hooks.example', 'hooks.example'],
            Webhooks::pinned('https://hooks.example/hook?a=1&b=2', '93.184.215.14'),
        );
        self::assertSame(
            ['https://[2606:4700::1111]:8443/', 'hooks.example:8443', 'hooks.example'],
            Webhooks::pinned('https://hooks.example:8443', '2606:4700::1111'),
        );
    }

    public function testOnlyPublicHttpsDestinationsAreAccepted(): void
    {
        $refused = ['http://hooks.example/x', 'https://localhost/x', 'https://127.0.0.1/x', 'https://[::1]/x',
            'https://10.0.0.5/x', 'https://169.254.169.254/latest', 'https://100.64.0.1/x', 'https://[fd00::1]/x',
            'https://[::ffff:127.0.0.1]/x', 'https://user:pass@hooks.example/x', 'https://hooks.example\\@evil/x'];
        foreach ($refused as $bad) {
            self::assertFalse(Webhooks::publicHttps($bad, false), $bad);
        }
        self::assertTrue(Webhooks::publicHttps('https://93.184.215.14/hook', false));
        self::assertTrue(Webhooks::publicHttps('https://hooks.example/hook', false));
    }
}
