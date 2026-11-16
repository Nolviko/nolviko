<?php

declare(strict_types=1);

namespace Nolviko\Modules\Editor\Domain;

/**
 * A Markdown subset written as BBCode (2026-11-08). The composer converts on submit when the author picks
 * Markdown, so storage, rendering and sanitizing stay the single BBCode path (BbcodeParser/Renderer, ADR-012):
 * nothing here produces HTML, and every link still passes UrlPolicy when it is rendered.
 *
 * Blocks: fenced code (```), `> ` quotes, `- `/`* ` and `1. ` lists, `#` headings (bold, large). Inline:
 * **bold**, *italic* / _italic_, ~~strike~~, `code`, [text](https://…), ![alt](https://…). Anything else stays as
 * typed text.
 */
final class MarkdownToBbcode
{
    public const FORMAT = 'markdown';

    public static function convert(string $markdown): string
    {
        $lines = preg_split('/\R/u', str_replace("\r\n", "\n", $markdown)) ?: [];
        $out = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            if (preg_match('/^\s*```/u', $line) === 1) {
                $code = [];

                for ($i++; $i < $count && preg_match('/^\s*```/u', $lines[$i]) !== 1; $i++) {
                    $code[] = $lines[$i];
                }

                $out[] = '[code]' . implode("\n", $code) . '[/code]';
                continue;
            }

            if (preg_match('/^\s*>\s?(.*)$/u', $line) === 1) {
                $quote = [];

                for (; $i < $count && preg_match('/^\s*>\s?(.*)$/u', $lines[$i], $m) === 1; $i++) {
                    $quote[] = $m[1];
                }

                $i--;
                $out[] = '[quote]' . self::convert(implode("\n", $quote)) . '[/quote]';
                continue;
            }

            if (preg_match('/^\s*([-*+]|\d{1,3}[.)])\s+/u', $line, $first) === 1) {
                $ordered = ctype_digit(rtrim($first[1], '.)'));
                $items = [];

                // One list per marker kind: a numbered list right after a bulleted one starts a new list.
                $marker = $ordered ? '\d{1,3}[.)]' : '[-*+]';

                for (; $i < $count && preg_match('/^\s*' . $marker . '\s+(.*)$/u', $lines[$i], $m) === 1; $i++) {
                    $items[] = '[*]' . self::inline($m[1]);
                }

                $i--;
                $out[] = ($ordered ? '[list=1]' : '[list]') . "\n" . implode("\n", $items) . "\n[/list]";
                continue;
            }

            if (preg_match('/^\s{0,3}#{1,6}\s+(.*?)\s*#*\s*$/u', $line, $m) === 1) {
                $out[] = '[size=large][b]' . self::inline($m[1]) . '[/b][/size]';
                continue;
            }

            $out[] = self::inline($line);
        }

        return implode("\n", $out);
    }

    /** Inline marks of one line; `code` spans are kept apart so their content is not converted. */
    private static function inline(string $text): string
    {
        $parts = preg_split('/(`[^`\n]+`)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                // No inline code tag in BBCode: keep the text, marked as it was typed.
                continue;
            }

            $part = (string) preg_replace('/!\[([^\]\n]*)\]\((https?:\/\/[^\s)]+)\)/u', '[img]$2[/img]', $part);
            $part = (string) preg_replace('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/u', '[url=$2]$1[/url]', $part);
            $part = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '[b]$1[/b]', $part);
            $part = (string) preg_replace('/__(?=\S)(.+?)(?<=\S)__/u', '[b]$1[/b]', $part);
            $part = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/u', '[s]$1[/s]', $part);
            $part = (string) preg_replace(
                '/(?<![\p{L}\p{N}*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\p{L}\p{N}*])/u',
                '[i]$1[/i]',
                $part,
            );
            $parts[$index] = (string) preg_replace(
                '/(?<![\p{L}\p{N}_])_(?=\S)([^_\n]+?)(?<=\S)_(?![\p{L}\p{N}_])/u',
                '[i]$1[/i]',
                $part,
            );
        }

        return implode('', $parts);
    }
}
