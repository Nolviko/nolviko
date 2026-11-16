<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Users;

use Nolviko\Modules\Users\Domain\IdentityNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * M02.01 normalization policy (IdentityNormalizer docblock): which names and addresses count as the same.
 */
final class IdentityNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function sameUsernames(): array
    {
        return [
            'case' => ['ADMIN', 'admin'],
            'fullwidth (NFKC)' => ["\u{FF21}\u{FF24}\u{FF2D}\u{FF29}\u{FF2E}", 'admin'],
            'ligature (NFKC)' => ["\u{FB01}nn", 'finn'],
            'composed vs decomposed' => ["Jose\u{0301}", "jos\u{00E9}"],
            'surrounding space' => ['  admin ', 'admin'],
            'Turkish dotted capital I' => ["\u{0130}SMA\u{0130}L", 'ismail'],
            'Turkish plain capital I folds to i' => ['IRMAK', 'irmak'],
            'German sharp s (full folding)' => ['STRASSE', "stra\u{00DF}e"],
            'Greek final sigma' => ["\u{039F}\u{0394}\u{039F}\u{03A3}", "\u{03BF}\u{03B4}\u{03BF}\u{03C2}"],
        ];
    }

    #[DataProvider('sameUsernames')]
    public function testEquivalentUsernamesCollide(string $a, string $b): void
    {
        self::assertSame(IdentityNormalizer::username($a), IdentityNormalizer::username($b));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function differentUsernames(): array
    {
        return [
            'Turkish dotless i is its own letter' => ["\u{0131}l\u{0131}k", 'ilik'],
            'emoji differ' => ["\u{1F600}", "\u{1F601}"],
            'digits are not letters' => ['adm1n', 'admin'],
            'inner space is kept' => ['ad min', 'admin'],
        ];
    }

    #[DataProvider('differentUsernames')]
    public function testDifferentUsernamesStayDifferent(string $a, string $b): void
    {
        self::assertNotSame(IdentityNormalizer::username($a), IdentityNormalizer::username($b));
    }

    public function testEmojiAndNormalizationAreStable(): void
    {
        $name = "Caf\u{00E9} \u{1F600}\u{FE0F}";
        $once = IdentityNormalizer::username($name);

        self::assertSame("caf\u{00E9} \u{1F600}\u{FE0F}", $once);
        self::assertSame($once, IdentityNormalizer::username($once), 'normalizing twice changes nothing');
    }

    public function testEmailIsTrimmedAndLowerCased(): void
    {
        self::assertSame('ali.veli@example.com', IdentityNormalizer::email("  Ali.Veli@EXAMPLE.com\n"));
        $unicode = IdentityNormalizer::email("\u{00DC}SER@B\u{00DC}CHER.example");

        self::assertSame("\u{00FC}ser@b\u{00FC}cher.example", $unicode);
    }
}
