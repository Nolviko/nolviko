<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

/** A closed data schema produces compact CSS; URL, expression, selector and executable input are impossible. */
final class ThemeTokens
{
    public const DEFAULTS = [
        'light' => ['paper' => '#f5f7f4', 'surface' => '#ffffff', 'ink' => '#15211c', 'muted' => '#414f48',
            'accent' => '#0a6e5c', 'accent_ink' => '#ffffff', 'focus' => '#005fcc', 'border' => '#64766b'],
        'dark' => ['paper' => '#0d1412', 'surface' => '#141d1a', 'ink' => '#e7eeea', 'muted' => '#b7c4be',
            'accent' => '#45d1b1', 'accent_ink' => '#06231c', 'focus' => '#6bdec4', 'border' => '#748b80'],
        'radius' => 10, 'spacing' => 16, 'width' => 1120, 'font' => 'design', 'density' => 'comfortable',
    ];

    /**
     * Ready-made accent colours (2026-11-08, site setting `appearance.accent`, chosen in the installer and in
     * /admin/site): accent and accent_ink for light and dark mode. Every pair passes the same contrast rules as
     * clean() (ThemeTokensTest); green is the default palette above.
     */
    public const ACCENTS = [
        'green' => ['light' => ['#0a6e5c', '#ffffff'], 'dark' => ['#45d1b1', '#06231c']],
        'blue' => ['light' => ['#1d5bbf', '#ffffff'], 'dark' => ['#7fb2ff', '#0a1a33']],
        'yellow' => ['light' => ['#8a5a00', '#ffffff'], 'dark' => ['#f2c14e', '#2a1e00']],
        'red' => ['light' => ['#b42318', '#ffffff'], 'dark' => ['#ff8a80', '#3a0a06']],
        'purple' => ['light' => ['#6941c6', '#ffffff'], 'dark' => ['#b9a3ff', '#1f1240']],
    ];

    /**
     * CSS that sets the chosen accent over the theme's own (nothing for the default or an unknown name).
     */
    public static function accent(?string $name): string
    {
        $palette = self::ACCENTS[$name ?? ''] ?? null;

        if ($palette === null || $name === 'green') {
            return '';
        }

        $vars = static fn(array $pair): string => '--theme-accent:' . $pair[0] . ';--theme-accent-ink:' . $pair[1]
            . ';';

        return ':root{' . $vars($palette['light']) . '}html[data-appearance="dark"]{' . $vars($palette['dark'])
            . '}@media(prefers-color-scheme:dark){html[data-appearance="system"]{' . $vars($palette['dark']) . '}}';
    }

    public static function clean(array $input): array
    {
        if (array_diff(array_keys($input), array_keys(self::DEFAULTS)) !== []) {
            throw new TemplateException('theme_tokens_invalid');
        }
        $tokens = self::DEFAULTS;
        foreach (['light', 'dark'] as $mode) {
            $palette = $input[$mode] ?? [];
            if (!is_array($palette) || array_diff(array_keys($palette), array_keys($tokens[$mode])) !== []) {
                throw new TemplateException('theme_tokens_invalid');
            }
            foreach ($palette as $key => $color) {
                if (!is_string($color) || preg_match('/^#[a-f0-9]{6}$/Di', $color) !== 1) {
                    throw new TemplateException('theme_tokens_invalid');
                }
                $tokens[$mode][$key] = strtolower($color);
            }
            $colors = $tokens[$mode];
            foreach (
                [['ink', 'paper', 4.5], ['ink', 'surface', 4.5], ['muted', 'paper', 4.5],
                ['muted', 'surface', 4.5], ['accent', 'paper', 4.5], ['accent_ink', 'accent', 4.5],
                ['focus', 'paper', 3], ['focus', 'surface', 3], ['border', 'surface', 3]] as [$a, $b, $minimum]
            ) {
                if (self::contrast($colors[$a], $colors[$b]) < $minimum) {
                    throw new TemplateException('theme_contrast_failed');
                }
            }
        }
        foreach (['radius' => [0, 24], 'spacing' => [8, 24], 'width' => [640, 1600]] as $key => [$min, $max]) {
            $value = $input[$key] ?? $tokens[$key];
            if (!is_int($value) || $value < $min || $value > $max) {
                throw new TemplateException('theme_tokens_invalid');
            }
            $tokens[$key] = $value;
        }
        $choices = ['font' => ['design', 'system', 'serif', 'mono'], 'density' => ['comfortable', 'compact']];
        foreach ($choices as $key => $values) {
            $value = $input[$key] ?? $tokens[$key];
            if (!in_array($value, $values, true)) {
                throw new TemplateException('theme_tokens_invalid');
            }
            $tokens[$key] = $value;
        }
        return $tokens;
    }

    public static function css(array $input): string
    {
        $tokens = self::clean($input);
        $palette = static function (array $colors): string {
            $css = '';
            foreach ($colors as $key => $value) {
                $css .= '--theme-' . str_replace('_', '-', $key) . ':' . $value . ';';
            }
            return $css . '--page-paper:var(--theme-paper);--page-ink:var(--theme-ink);'
                . '--focus-ring:var(--theme-focus);';
        };
        $light = $palette($tokens['light']);
        $dark = $palette($tokens['dark']);
        // ADR-033: only 'design' names the self-hosted faces (fonts.css); the other stacks never fetch a font file.
        [$font, $display, $mono] = match ($tokens['font']) {
            'design' => ['"Figtree",system-ui,sans-serif', '"Bricolage Grotesque",system-ui,sans-serif',
                '"JetBrains Mono",Consolas,monospace'],
            'serif' => ['Georgia,serif', 'Georgia,serif', 'Consolas,monospace'],
            'mono' => ['Consolas,monospace', 'Consolas,monospace', 'Consolas,monospace'],
            default => ['system-ui,sans-serif', 'system-ui,sans-serif', 'Consolas,monospace'],
        };
        return ':root{' . $light . '--theme-radius:' . $tokens['radius'] . 'px;--theme-spacing:'
            . $tokens['spacing'] . 'px;--theme-width:' . $tokens['width'] . 'px;--theme-font:' . $font
            . ';--theme-font-display:' . $display . ';--theme-font-mono:' . $mono
            . ';--theme-density:' . ($tokens['density'] === 'compact' ? '.65' : '1') . ';}'
            . 'html[data-appearance="dark"]{' . $dark . '}'
            . '@media(prefers-color-scheme:dark){html[data-appearance="system"]{' . $dark . '}}';
    }

    public static function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $sum = 0.0;
            foreach ([0.2126, 0.7152, 0.0722] as $index => $weight) {
                $channel = hexdec(substr($hex, 1 + $index * 2, 2)) / 255;
                $sum += $weight * ($channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4);
            }
            return $sum;
        };
        $first = $luminance($a);
        $second = $luminance($b);
        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }
}
