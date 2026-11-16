<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Domain;

/**
 * @mentions in a post's BBCode source (2026-11-08): `@name` for names without spaces and `@"Name With Spaces"`.
 * Text inside [quote] and [code] does not count, so quoting a post never mentions its people again. At most MAX
 * names per post; the caller looks them up and notifies (CommunityNotifications).
 */
final class Mentions
{
    public const MAX = 10;

    /** The @name / @"Name With Spaces" token (same rules as names()). */
    private const TOKEN = '~(?<![\p{L}\p{N}_@./])@(?:&quot;([^"&\r\n]{3,32})&quot;|"([^"\r\n]{3,32})"'
        . '|([\p{L}\p{N}][\p{L}\p{N}\p{M}._-]{1,30}[\p{L}\p{N}\p{M}]))~u';

    /**
     * Rendered post HTML with @mentions turned into links (2026-11-08). Only text outside links, code, quotes and
     * spoiler titles changes; the target is `$url(name)` (the /members?name= redirect), so nothing is looked up
     * here and the stored rendering stays independent of the member list.
     *
     * @param callable(string): string $url
     */
    public static function link(string $html, callable $url): string
    {
        if (!str_contains($html, '@')) {
            return $html;
        }

        $parts = preg_split('~(<[^>]*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$html];
        $skip = 0;

        foreach ($parts as $index => $part) {
            if ($part !== '' && $part[0] === '<') {
                if (preg_match('~^<(a|code|pre|cite|summary)\b~i', $part) === 1) {
                    $skip++;
                } elseif (preg_match('~^</(a|code|pre|cite|summary)>~i', $part) === 1) {
                    $skip = max(0, $skip - 1);
                }

                continue;
            }

            if ($skip === 0 && str_contains($part, '@')) {
                $parts[$index] = (string) preg_replace_callback(self::TOKEN, static function (
                    array $match,
                ) use ($url): string {
                    $name = html_entity_decode($match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2]
                        : $match[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                    return '<a class="mention" href="' . htmlspecialchars($url($name), ENT_QUOTES, 'UTF-8') . '">'
                        . $match[0] . '</a>';
                }, $part);
            }
        }

        return implode('', $parts);
    }

    /**
     * @return list<string> Distinct names as written, in order of first appearance.
     */
    public static function names(string $content): array
    {
        $text = $content;

        // Innermost blocks first, a bounded number of times, so nested quotes are removed too.
        for ($round = 0; $round < 8; $round++) {
            $stripped = (string) preg_replace(
                '~\[(quote|code)(?:=[^\]]*)?\](?:(?!\[(?:quote|code)(?:=[^\]]*)?\]).)*?\[/\1\]~is',
                ' ',
                $text,
            );

            if ($stripped === $text) {
                break;
            }

            $text = $stripped;
        }

        preg_match_all(
            '~(?<![\p{L}\p{N}_@./])@(?:"([^"\r\n]{3,32})"|([\p{L}\p{N}][\p{L}\p{N}\p{M}._-]{1,30}[\p{L}\p{N}\p{M}]))~u',
            $text,
            $matches,
            PREG_SET_ORDER,
        );
        $names = [];

        foreach ($matches as $match) {
            $name = trim(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''));
            $key = mb_strtolower($name, 'UTF-8');

            if ($name !== '' && !isset($names[$key])) {
                $names[$key] = $name;
            }

            if (count($names) >= self::MAX) {
                break;
            }
        }

        return array_values($names);
    }
}
