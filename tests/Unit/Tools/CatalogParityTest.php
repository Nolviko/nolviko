<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Tools;

use Nolviko\Tools\I18n\CatalogParity;
use PHPUnit\Framework\TestCase;

/** Nested ICU branch labels are not mistaken for placeholders; omitted and mistyped arguments are rejected. */
final class CatalogParityTest extends TestCase
{
    public function testNestedBranchesAndNumberArgumentsMatch(): void
    {
        $en = ['core.sample' => '{status, select, active {{count, plural, one {# item} other {# items}}} '
            . 'other {Unknown {name}}}'];
        $tr = ['core.sample' => '{status, select, active {{count, plural, one {# öğe} other {# öğe}}} '
            . 'other {Bilinmiyor {name}}}'];

        self::assertSame([], (new CatalogParity())->compare($en, $tr, 'tr'));
    }

    public function testMissingAndAdditionalKeysAreReported(): void
    {
        self::assertSame(
            ['core.extra' => 'catalog_key_unknown', 'core.original' => 'catalog_key_missing'],
            (new CatalogParity())->compare(['core.original' => 'Hello'], ['core.extra' => 'Merhaba'], 'tr')
        );
    }

    public function testMissingArgumentAndChangedTypeAreNotAccepted(): void
    {
        $reference = ['core.a' => '{name}', 'core.b' => '{count, number}'];
        $translated = ['core.a' => 'Ad', 'core.b' => '{count}'];

        self::assertSame(
            ['core.a' => 'catalog_arguments_mismatch', 'core.b' => 'catalog_arguments_mismatch'],
            (new CatalogParity())->compare($reference, $translated, 'tr')
        );
    }

    public function testInvalidIcuDoesNotPass(): void
    {
        self::assertSame(['core.a' => 'catalog_icu_invalid'], (new CatalogParity())->compare(
            ['core.a' => '{count, plural, one {One} other {Many}}'],
            ['core.a' => '{count, plural, one {Bir}}'],
            'tr',
        ));
    }

    public function testQuotedSyntaxAndDoubledApostrophesAreLiterals(): void
    {
        self::assertSame([], (new CatalogParity())->compare(
            ['core.a' => "'{ignored}' and '' {name}"],
            ['core.a' => "'{yoksay}' ve '' {name}"],
            'tr',
        ));
    }
}
