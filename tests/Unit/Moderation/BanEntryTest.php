<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Moderation;

use Nolviko\Modules\Moderation\Domain\BanEntry;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M04.09a: ban values are normalized and CIDR ranges become their first and last address, for IPv4 and IPv6.
 */
final class BanEntryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, ?string}>
     */
    public static function valid(): iterable
    {
        yield 'ipv4' => [BanEntry::IP, ' 192.0.2.7 ', '192.0.2.7', null];
        yield 'ipv6 normalized' => [BanEntry::IP, '2001:DB8:0:0::1', '2001:db8::1', null];
        yield 'ipv4-mapped is ipv4' => [BanEntry::IP, '::ffff:192.0.2.9', '192.0.2.9', null];
        yield 'ipv4 /24' => [BanEntry::IP_RANGE, '192.0.2.77/24', '192.0.2.0', '192.0.2.255'];
        yield 'ipv4 /13' => [BanEntry::IP_RANGE, '10.9.8.7/13', '10.8.0.0', '10.15.255.255'];
        yield 'ipv4 /32' => [BanEntry::IP_RANGE, '198.51.100.1/32', '198.51.100.1', null];
        yield 'ipv6 /32' => [
            BanEntry::IP_RANGE,
            '2001:db8:abcd::1/32',
            '2001:db8::',
            '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff',
        ];
        yield 'ipv6 /63' => [
            BanEntry::IP_RANGE,
            '2001:db8:0:3::/63',
            '2001:db8:0:2::',
            '2001:db8:0:3:ffff:ffff:ffff:ffff',
        ];
        yield 'email' => [BanEntry::EMAIL, 'Spam@Example.COM', 'spam@example.com', null];
        yield 'domain' => [BanEntry::EMAIL_DOMAIN, '@Mailinator.com', 'mailinator.com', null];
    }

    #[DataProvider('valid')]
    public function testValuesAreNormalized(string $type, string $value, string $first, ?string $last): void
    {
        $entry = BanEntry::parse($type, $value);

        self::assertSame($type, $entry->type);
        self::assertSame([$first, $last], $entry->display());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'type' => ['user', 'x', 'ban_type_invalid'];
        yield 'not an ip' => [BanEntry::IP, '192.0.2', 'ban_value_invalid'];
        yield 'range without prefix' => [BanEntry::IP_RANGE, '192.0.2.0', 'ban_value_invalid'];
        yield 'prefix 0 bans everyone' => [BanEntry::IP_RANGE, '0.0.0.0/0', 'ban_value_invalid'];
        yield 'prefix too long' => [BanEntry::IP_RANGE, '192.0.2.0/33', 'ban_value_invalid'];
        yield 'ipv6 prefix too long' => [BanEntry::IP_RANGE, '2001:db8::/129', 'ban_value_invalid'];
        yield 'email' => [BanEntry::EMAIL, 'not-an-email', 'ban_value_invalid'];
        yield 'domain without dot' => [BanEntry::EMAIL_DOMAIN, 'localhost', 'ban_value_invalid'];
        yield 'domain with space' => [BanEntry::EMAIL_DOMAIN, 'bad domain.com', 'ban_value_invalid'];
    }

    #[DataProvider('invalid')]
    public function testInvalidValuesAreRefused(string $type, string $value, string $diagnostic): void
    {
        try {
            BanEntry::parse($type, $value);
            self::fail('expected ' . $diagnostic);
        } catch (ThreadRuleViolation $violation) {
            self::assertSame($diagnostic, $violation->diagnostic);
        }
    }

    public function testPackIgnoresNonAddresses(): void
    {
        self::assertNull(BanEntry::pack(null));
        self::assertNull(BanEntry::pack(''));
        self::assertNull(BanEntry::pack('example.com'));
        self::assertSame(4, strlen((string) BanEntry::pack('::ffff:192.0.2.1')));
    }
}
