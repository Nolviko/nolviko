<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Nolviko\Core\Locale\LocaleDirection;

/**
 * Minimal, dependency-free HTML document for system pages (home placeholder, errors).
 *
 * Deliberately not Twig: error pages must still render when templates, themes or caches are broken.
 * Every text value is escaped here; callers pass plain (already translated) text only.
 *
 * Styling is one fixed inline block (STYLE). It needs no asset file, base path or nonce: SecurityHeaders allows
 * exactly this block by its SHA-256 hash, so any change to STYLE is picked up automatically and nothing else inline
 * is permitted. The brand mark comes from CSS `content`, so no untranslated text is written into the document.
 */
final class HtmlPage
{
    public const STYLE = ':root{color-scheme:light dark;--bg:#f5f7f4;--card:#fff;--ink:#15211c;--muted:#414f48;'
        . '--line:#dfe5e0;--accent:rgb(10 110 92);--accent-ink:#fff;--soft:#e2efe9}'
        . '@media(prefers-color-scheme:dark){:root{--bg:#101714;--card:#17201c;--ink:#e6ede9;--muted:#a9b6af;'
        . '--line:#2a3631;--accent:#5fc4a8;--accent-ink:rgb(11 21 18);--soft:#1d2c26}}'
        . '*{box-sizing:border-box}'
        . 'body{margin:0;min-height:100vh;display:flex;flex-direction:column;background:var(--bg);color:var(--ink);'
        . 'font:400 16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}'
        . 'header{padding:20px 24px}.brand{color:inherit;text-decoration:none}'
        . '.brand::before{content:"n";display:inline-grid;place-items:center;width:28px;height:28px;'
        . 'background:var(--accent);color:var(--accent-ink);font:800 17px/1 system-ui,sans-serif;border-radius:9px;'
        . 'border-end-start-radius:2px;vertical-align:middle;margin-inline-end:9px}'
        . '.brand::after{content:"nolviko";font:800 21px/1 system-ui,sans-serif;letter-spacing:-.03em;'
        . 'vertical-align:middle}'
        . 'main{flex:1;width:100%;max-width:560px;margin:0 auto;padding:6vh 16px 64px}'
        . '.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:32px}'
        . '.code{display:inline-block;margin:0 0 14px;padding:4px 10px;border-radius:999px;background:var(--soft);'
        . 'color:var(--accent);font:700 13px/1.4 ui-monospace,Consolas,monospace;letter-spacing:.04em}'
        . 'h1{margin:0 0 10px;font-size:clamp(24px,4vw,30px);line-height:1.15;letter-spacing:-.02em;'
        . 'text-wrap:balance}'
        . 'p{margin:0 0 12px;color:var(--muted)}'
        . 'ul{margin:16px 0 0;padding:0;list-style:none;border-top:1px solid var(--line)}'
        . 'li{padding:10px 0;border-bottom:1px solid var(--line);font-size:14px;overflow-wrap:anywhere}'
        . '@media(max-width:480px){.card{padding:22px}}'
        . '.home{display:inline-block;margin-top:8px;color:var(--accent);font-weight:600}'
        . '@media(forced-colors:active){.brand::before{border:1px solid CanvasText}}';

    /**
     * @param list<string> $paragraphs
     * @param list<string> $items Rendered as a bullet list after the paragraphs.
     * @param int|null $status HTTP status shown as a small code label above the title (errors only).
     * @param string|null $homeUrl The site's home page: the logo and a link under the text lead there.
     * @param string $homeLabel Text of that link.
     */
    public static function render(
        string $language,
        string $title,
        array $paragraphs = [],
        array $items = [],
        ?int $status = null,
        ?string $homeUrl = null,
        string $homeLabel = '',
    ): string {
        $body = $status === null ? '' : '<p class="code">' . $status . '</p>';
        $body .= '<h1>' . self::escape($title) . '</h1>';

        foreach ($paragraphs as $paragraph) {
            $body .= '<p>' . self::escape($paragraph) . '</p>';
        }

        if ($items !== []) {
            $body .= '<ul>' . implode('', array_map(self::listItem(...), $items)) . '</ul>';
        }

        $home = $homeUrl === null ? null : self::escape($homeUrl);

        if ($home !== null && $homeLabel !== '') {
            $body .= '<p><a class="home" href="' . $home . '">' . self::escape($homeLabel) . '</a></p>';
        }

        $brand = $home === null ? '<header aria-hidden="true"><span class="brand"></span></header>'
            : '<header><a class="brand" href="' . $home . '" aria-label="' . self::escape($homeLabel) . '"></a>'
                . '</header>';

        return '<!doctype html>' . "\n"
            . '<html lang="' . self::escape($language) . '" dir="' . LocaleDirection::of($language) . '">'
            . '<head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex">'
            . '<title>' . self::escape($title) . '</title><style>' . self::STYLE . '</style></head>'
            . '<body>' . $brand . '<main><div class="card">' . $body . '</div></main>'
            . '</body></html>' . "\n";
    }

    /** CSP source expression that allows exactly STYLE and nothing else inline. */
    public static function styleSource(): string
    {
        return "'sha256-" . base64_encode(hash('sha256', self::STYLE, true)) . "'";
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function listItem(string $item): string
    {
        return '<li>' . self::escape($item) . '</li>';
    }
}
