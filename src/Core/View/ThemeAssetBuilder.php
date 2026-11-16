<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

/** Generated token CSS is minified by construction; trusted asset bodies retain their language semantics. */
final class ThemeAssetBuilder
{
    public static function build(string $name, string $source): string
    {
        if (
            strlen($source) > 262144 || !mb_check_encoding($source, 'UTF-8') || str_contains($source, "\0")
            || preg_match('~</(?:style|script)\b~i', $source) === 1
        ) {
            throw new TemplateException('theme_asset_invalid');
        }
        if (
            str_ends_with($name, '.css')
            && preg_match(
                '~@import|url\s*\(|expression\s*\(|javascript:|(?<![a-z-])behavior\s*:|\\\\~i',
                self::checked($name, $source),
            ) === 1
        ) {
            throw new TemplateException('theme_asset_invalid');
        }
        // JavaScript strings, regexps and automatic semicolon insertion stay byte-for-byte intact.
        // CSS uses a conservative state machine; generated token CSS is already compact.
        return str_ends_with($name, '.css') ? ThemeCssMinifier::compact($source)
            : trim($source, " \t\r\n") . "\n";
    }

    /**
     * ADR-033: the core fonts.css may reference only bundled WOFF2 files beside the assets directory; every other
     * stylesheet keeps the full url() ban, so themes still cannot load remote or arbitrary resources.
     */
    private static function checked(string $name, string $source): string
    {
        return $name === 'fonts.css'
            ? (string) preg_replace('~url\("\.\./fonts/[a-z0-9-]+\.woff2"\) format\("woff2"\)~', '', $source)
            : $source;
    }
}
