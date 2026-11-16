<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Users;

use Nolviko\Modules\Users\Domain\IdentityRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M02.15a: username and email rules.
 */
final class IdentityRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function usernames(): iterable
    {
        yield 'plain' => ['Deniz', 'Deniz'];
        yield 'trimmed' => ['  Ali Veli  ', 'Ali Veli'];
        yield 'turkish letters' => ['Şükrü_Öz', 'Şükrü_Öz'];
        yield 'NFC' => ["Jose\u{0301}", "Jos\u{00E9}"];
        yield 'dots and hyphens' => ['a.b-c', 'a.b-c'];
        yield 'too short' => ['ab', null];
        yield 'too long' => [str_repeat('a', 33), null];
        yield 'starts with a dot' => ['.admin', null];
        yield 'ends with a hyphen' => ['admin-', null];
        yield 'double space' => ['a  b', null];
        yield 'symbols' => ['<script>', null];
        yield 'control character' => ["ad\u{0007}min", null];
        yield 'emoji' => ['cool😀guy', null];
        yield 'invalid UTF-8' => ["ad\xC3min", null];
    }

    #[DataProvider('usernames')]
    public function testUsername(string $input, ?string $expected): void
    {
        self::assertSame($expected, IdentityRules::username($input));
    }

    public function testEmail(): void
    {
        self::assertSame('admin@example.com', IdentityRules::email(' admin@example.com '));
        self::assertSame('kullanıcı@example.com', IdentityRules::email('kullanıcı@example.com'));
        self::assertNull(IdentityRules::email('not-an-address'));
        self::assertNull(IdentityRules::email('a@b@example.com'));
        self::assertNull(IdentityRules::email(''));
        self::assertNull(IdentityRules::email(str_repeat('a', 250) . '@example.com'));
    }
}
