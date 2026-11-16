<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Domain;

/** User text becomes a small AND/prefix query; user-supplied Boolean operators never reach the backend. */
final readonly class SearchQuery
{
    /** @param list<string> $terms */
    private function __construct(public string $text, public array $terms)
    {
    }

    public static function parse(string $text): ?self
    {
        if (
            strlen($text) > 512 || !mb_check_encoding($text, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $text) === 1
        ) {
            return null;
        }

        $text = trim($text);

        if (grapheme_strlen($text) > 120) {
            return null;
        }

        preg_match_all('/[\p{L}\p{N}\p{M}]+/u', $text, $matches);
        $terms = array_values(array_unique(array_map(
            self::fold(...),
            $matches[0],
        )));

        if ($terms === [] || count($terms) > 6) {
            return null;
        }

        foreach ($terms as $term) {
            if (grapheme_strlen($term) < 3 || grapheme_strlen($term) > 32) {
                return null;
            }
        }

        return new self($text, $terms);
    }

    public function boolean(): string
    {
        return implode(' ', array_map(static fn(string $term): string => '+' . $term . '*', $this->terms));
    }

    private static function fold(string $term): string
    {
        // Normalize the dotted Latin I without removing other scripts' combining marks.
        return str_replace("i\u{0307}", 'i', mb_strtolower($term, 'UTF-8'));
    }
}
