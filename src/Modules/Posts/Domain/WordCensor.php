<?php

declare(strict_types=1);

namespace Nolviko\Modules\Posts\Domain;

/**
 * Word censor (2026-11-08, site setting `antispam.censored_words`): whole words, case-insensitive, shown as
 * asterisks in rendered post HTML. Only text between tags changes, never markup or attributes, and the stored
 * source stays as written, so removing a word from the list shows it again. Refusing a post is the separate
 * blocked-word list (ContentPolicy).
 */
final class WordCensor
{
    /**
     * The same masking for plain text such as thread titles (2026-11-08).
     *
     * @param list<string> $words
     */
    public static function text(string $text, array $words): string
    {
        $masked = self::html(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), $words);

        return html_entity_decode($masked, ENT_QUOTES, 'UTF-8');
    }

    /**
     * @param list<string> $words
     */
    public static function html(string $html, array $words): string
    {
        $words = array_values(array_filter(array_map('trim', $words), static fn(string $word): bool => $word !== ''));

        if ($words === [] || $html === '') {
            return $html;
        }

        $pattern = '/(?<![\p{L}\p{N}])(?:' . implode('|', array_map(
            static fn(string $word): string => preg_quote($word, '/'),
            $words,
        )) . ')(?![\p{L}\p{N}])/iu';

        return (string) preg_replace_callback(
            '/(^|>)([^<]+)/u',
            static fn(array $text): string => $text[1] . (string) preg_replace_callback(
                $pattern,
                static fn(array $word): string => str_repeat('*', max(1, mb_strlen($word[0], 'UTF-8'))),
                $text[2],
            ),
            $html,
        );
    }
}
