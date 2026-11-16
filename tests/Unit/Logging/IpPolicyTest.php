<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Logging;

use Nolviko\Core\Logging\IpPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IpPolicyTest extends TestCase
{
    /**
     * @return list<array{0: IpPolicy, 1: string|null, 2: string|null}>
     */
    public static function addresses(): array
    {
        return [
            [IpPolicy::Full, '203.0.113.77', '203.0.113.77'],
            [IpPolicy::Full, '2001:DB8:1:2:3:4:5:6', '2001:db8:1:2:3:4:5:6'],
            [IpPolicy::Truncated, '203.0.113.77', '203.0.113.0'],
            [IpPolicy::Truncated, '2001:db8:1:2:3:4:5:6', '2001:db8:1::'],
            [IpPolicy::None, '203.0.113.77', null],
            [IpPolicy::Truncated, 'not-an-ip', 'invalid'],
            [IpPolicy::Truncated, null, null],
            [IpPolicy::Full, '', null],
        ];
    }

    #[DataProvider('addresses')]
    public function testPolicyIsApplied(IpPolicy $policy, ?string $address, ?string $expected): void
    {
        self::assertSame($expected, $policy->apply($address));
    }
}
