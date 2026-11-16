<?php

declare(strict_types=1);

namespace Nolviko\Tools\I18n;

/**
 * Finds user-visible text that bypasses the translator (AGENTS.md i18n rule).
 *
 * Heuristic, by design: in PHP, a string literal is user-visible when it reads like a sentence
 * (starts with an upper-case letter followed by a lower-case letter and contains whitespace; this
 * also catches the leading part of interpolated strings such as "Hello $name"). SQL keywords,
 * diagnostic IDs (`snake_case`), header values and paths do not match. In Twig, any text outside tags,
 * `{{ }}`, `{% %}` and `{# #}`, and the human-readable attributes, must not contain words.
 * Put `i18n-ignore` in a comment on the same line for a reviewed exception.
 */
final class LiteralScanner
{
    /** Controlled brand names that may appear untranslated. */
    public const ALLOWED_LITERALS = ['Nolviko'];

    private const IGNORE_MARKER = 'i18n-ignore';

    private const SENTENCE_PATTERN = '/^\p{Lu}\p{Ll}+.*\s/su';

    private const WORD_PATTERN = '/\p{L}{2,}/u';

    /** Twig code, comments and script/style blocks: not visible text. */
    private const TWIG_CODE_PATTERN = '/\{\{.*?\}\}|\{%.*?%\}|\{#.*?#\}|<(script|style)\b.*?<\/\1>/is';

    private const VISIBLE_ATTRIBUTE_PATTERN =
        '/\b(?:alt|title|placeholder|aria-label|aria-description|value|label)\s*=\s*"([^"]*)"/i';

    /**
     * @param list<string> $directories
     *
     * @return list<Finding>
     */
    public function scan(array $directories): array
    {
        $findings = [];

        foreach (SourceFiles::find($directories) as $path) {
            $content = file_get_contents($path);
            $fileFindings = str_ends_with($path, '.twig')
                ? $this->scanTwig($path, $content)
                : $this->scanPhp($path, $content);
            array_push($findings, ...$fileFindings);
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    public function scanPhp(string $path, string $content): array
    {
        $lines = explode("\n", $content);
        $findings = [];

        foreach (token_get_all($content) as $token) {
            if (!self::isStringToken($token)) {
                continue;
            }

            $text = self::unquote($token[1]);
            $line = $token[2];

            if (self::looksLikeSentence($text) && !self::isIgnoredLine($lines, $line)) {
                $findings[] = new Finding($path, $line, $text);
            }
        }

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    public function scanTwig(string $path, string $content): array
    {
        $lines = explode("\n", $content);
        $findings = [];

        // Blank out Twig code but keep offsets, so line numbers stay correct.
        $markup = preg_replace_callback(
            self::TWIG_CODE_PATTERN,
            static fn(array $match): string => preg_replace('/[^\n]/', ' ', $match[0]),
            $content,
        );

        foreach ($this->visibleTextSegments($markup) as [$offset, $text]) {
            $line = SourceFiles::lineOf($markup, $offset);

            if (self::containsWords($text) && !self::isIgnoredLine($lines, $line)) {
                $findings[] = new Finding($path, $line, trim($text));
            }
        }

        return $findings;
    }

    /**
     * Text between tags plus the values of human-readable attributes, with their byte offsets.
     *
     * @return list<array{0: int, 1: string}>
     */
    private function visibleTextSegments(string $markup): array
    {
        $segments = [];
        preg_match_all('/>([^<]+)</', '>' . $markup . '<', $texts, PREG_OFFSET_CAPTURE);

        foreach ($texts[1] as [$text, $offset]) {
            $segments[] = [max(0, $offset - 1), $text];
        }

        preg_match_all(self::VISIBLE_ATTRIBUTE_PATTERN, $markup, $attributes, PREG_OFFSET_CAPTURE);

        foreach ($attributes[1] as [$text, $offset]) {
            $segments[] = [$offset, $text];
        }

        return $segments;
    }

    private static function isStringToken(mixed $token): bool
    {
        return is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true);
    }

    private static function looksLikeSentence(string $text): bool
    {
        return preg_match(self::SENTENCE_PATTERN, $text) === 1 && !self::isAllowed($text);
    }

    private static function containsWords(string $text): bool
    {
        return preg_match(self::WORD_PATTERN, $text) === 1 && !self::isAllowed(trim($text));
    }

    private static function isAllowed(string $text): bool
    {
        return in_array($text, self::ALLOWED_LITERALS, true);
    }

    /**
     * @param list<string> $lines
     */
    private static function isIgnoredLine(array $lines, int $line): bool
    {
        return str_contains($lines[$line - 1] ?? '', self::IGNORE_MARKER);
    }

    private static function unquote(string $literal): string
    {
        $first = $literal[0] ?? '';

        if (($first === "'" || $first === '"') && str_ends_with($literal, $first) && strlen($literal) >= 2) {
            return substr($literal, 1, -1);
        }

        return $literal;
    }
}
