<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\View;

use Nolviko\Core\View\ThemeTokens;
use PHPUnit\Framework\TestCase;

/**
 * Ready-made accent colours (2026-11-08): every palette passes the token contrast rules in both modes.
 */
final class AccentPaletteTest extends TestCase
{
    public function testEveryAccentPassesTheContrastRules(): void
    {
        foreach (ThemeTokens::ACCENTS as $name => $palette) {
            foreach (['light', 'dark'] as $mode) {
                [$accent, $ink] = $palette[$mode];
                $tokens = [$mode => ['accent' => $accent, 'accent_ink' => $ink]];
                self::assertIsArray(ThemeTokens::clean($tokens), $name . ' ' . $mode);
                self::assertGreaterThanOrEqual(
                    4.5,
                    ThemeTokens::contrast($accent, ThemeTokens::DEFAULTS[$mode]['surface']),
                    $name . ' ' . $mode . ' on surface',
                );
            }
        }
    }

    public function testCssOnlyForANonDefaultKnownAccent(): void
    {
        self::assertSame('', ThemeTokens::accent(null));
        self::assertSame('', ThemeTokens::accent('green'));
        self::assertSame('', ThemeTokens::accent('red;}body{display:none'));
        self::assertStringContainsString('--theme-accent:#6941c6;', ThemeTokens::accent('purple'));
        self::assertStringContainsString(
            'html[data-appearance="dark"]{--theme-accent:#b9a3ff;',
            ThemeTokens::accent('purple'),
        );
    }
}
