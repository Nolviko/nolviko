<?php

declare(strict_types=1);

namespace Nolviko\Modules\Editor\Domain;

/**
 * One administrator-defined BBCode (MyCode, ADR-042): a pattern `[tag]{content}[/tag]` or
 * `[tag={param}]{content}[/tag]` mapped to an HTML template. The template is compiled once into literal HTML and
 * placeholders; compiling is the validation:
 *
 * - only the elements and attributes of the allow-list below, balanced, at most MAX_ELEMENTS of them;
 * - no `style`, no `id`, no event handler, no script, no URL except a checked absolute http(s) link;
 * - `{content}` (the rendered, already escaped inner BBCode) at most once and only between elements;
 * - `{param}` (escaped text) in text, `title` or, for the `url` kind only, as the whole `href`.
 *
 * The parameter itself is checked by its kind before a tag is recognised, exactly like the built-in tags, so a
 * refused value leaves the BBCode as literal text. Literal template text is decoded once and re-escaped.
 */
final readonly class CustomTag
{
    /** Parameter kinds: none (no `=`), plain text, http(s) link, whole number or one of the colour names. */
    public const PARAMETERS = ['none', 'text', 'url', 'int', 'color'];

    public const MAX_TEMPLATE = 2000;

    public const MAX_ELEMENTS = 30;

    /** Element => allowed attributes. */
    public const ELEMENTS = [
        'span' => ['class', 'title', 'dir'], 'div' => ['class', 'title', 'dir'], 'p' => ['class', 'dir'],
        'strong' => ['class'], 'em' => ['class'], 'b' => ['class'], 'i' => ['class'], 'u' => ['class'],
        's' => ['class'], 'del' => ['class'], 'ins' => ['class'], 'sub' => [], 'sup' => [], 'small' => ['class'],
        'mark' => ['class'], 'code' => ['class'], 'pre' => ['class'], 'kbd' => [], 'blockquote' => ['class'],
        'cite' => [], 'q' => [], 'abbr' => ['title'], 'ul' => ['class'], 'ol' => ['class'], 'li' => [],
        'a' => ['href', 'title', 'class'], 'details' => ['class', 'open'], 'summary' => [], 'h3' => ['class'],
        'h4' => ['class'], 'h5' => ['class'], 'h6' => ['class'], 'br' => [], 'hr' => ['class'],
    ];

    private const VOID = ['br', 'hr'];

    private const TAG = '/^[a-z]{1,20}$/D';

    /** Up to five class names; themes style them (never free CSS). */
    private const CLASSES = '/^[a-z][a-z0-9_-]{0,39}( [a-z][a-z0-9_-]{0,39}){0,4}$/D';

    private const NAME = '/^[^\[\]\r\n]{1,100}$/Du';

    /**
     * @param list<array{0: string, 1: string}> $parts [kind, value]: html (literal), content, param.
     */
    private function __construct(
        public string $tag,
        public string $parameter,
        private array $parts,
    ) {
    }

    /**
     * Reads an administrator's pattern: `[name]{content}[/name]` or `[name={param}]{content}[/name]`.
     *
     * @return array{0: string, 1: bool} Tag name and whether it takes a parameter.
     *
     * @throws CustomTagRejected `mycode_pattern_invalid` or `mycode_tag_reserved`.
     */
    public static function pattern(string $pattern): array
    {
        if (preg_match('~^\[([a-z]{1,20})(=\{param\})?\]\{content\}\[/\1\]$~D', trim($pattern), $match) !== 1) {
            throw new CustomTagRejected('mycode_pattern_invalid');
        }

        if (in_array($match[1], BbcodeParser::TAGS, true)) {
            throw new CustomTagRejected('mycode_tag_reserved');
        }

        return [$match[1], ($match[2] ?? '') !== ''];
    }

    /**
     * @throws CustomTagRejected `mycode_*` naming what was refused.
     */
    public static function compile(string $tag, string $parameter, string $template): self
    {
        if (preg_match(self::TAG, $tag) !== 1 || in_array($tag, BbcodeParser::TAGS, true)) {
            throw new CustomTagRejected('mycode_tag_reserved');
        }

        if (!in_array($parameter, self::PARAMETERS, true)) {
            throw new CustomTagRejected('mycode_parameter_invalid');
        }

        if ($template === '' || strlen($template) > self::MAX_TEMPLATE || !mb_check_encoding($template, 'UTF-8')) {
            throw new CustomTagRejected('mycode_template_invalid');
        }

        $pieces = preg_split('/(<[^<>]*>|\{content\}|\{param\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        $parts = [];
        $stack = [];
        $elements = 0;
        $contents = 0;

        foreach ($pieces === false ? [] : $pieces as $piece) {
            if ($piece === '') {
                continue;
            }

            if ($piece === '{content}') {
                if (++$contents > 1) {
                    throw new CustomTagRejected('mycode_content_once');
                }

                $parts[] = ['content', ''];
            } elseif ($piece === '{param}') {
                $parts[] = self::param($parameter);
            } elseif ($piece[0] === '<') {
                if (++$elements > self::MAX_ELEMENTS * 2) {
                    throw new CustomTagRejected('mycode_template_invalid');
                }

                foreach (self::element($piece, $parameter, $stack) as $part) {
                    $parts[] = $part;
                }
            } else {
                if (str_contains($piece, '<') || str_contains($piece, '>')) {
                    throw new CustomTagRejected('mycode_template_invalid');
                }

                $parts[] = ['html', self::escape(html_entity_decode($piece, ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
            }
        }

        if ($stack !== []) {
            throw new CustomTagRejected('mycode_template_unbalanced');
        }

        return new self($tag, $parameter, self::merge($parts));
    }

    /** Whether a BBCode's `=value` fits this tag's parameter kind (null: no `=` was written). */
    public function accepts(?string $value): bool
    {
        return match ($this->parameter) {
            'none' => $value === null,
            'text' => $value !== null && preg_match(self::NAME, $value) === 1,
            'url' => $value !== null && UrlPolicy::allowed($value, UrlPolicy::IMAGE_SCHEMES),
            'int' => $value !== null && preg_match('/^[0-9]{1,6}$/D', $value) === 1,
            'color' => $value !== null && in_array(strtolower($value), BbcodeParser::COLORS, true),
            default => false,
        };
    }

    /**
     * @param string $content Rendered inner HTML (already escaped by the renderer).
     * @param string|null $value The accepted parameter, raw; it is escaped here.
     */
    public function render(string $content, ?string $value): string
    {
        $html = '';

        foreach ($this->parts as [$kind, $literal]) {
            $html .= match ($kind) {
                'content' => $content,
                'param' => self::escape($this->parameter === 'color' ? strtolower((string) $value) : (string) $value),
                default => $literal,
            };
        }

        return $html;
    }

    /** @return array{0: string, 1: string} */
    private static function param(string $parameter): array
    {
        if ($parameter === 'none') {
            throw new CustomTagRejected('mycode_param_without_parameter');
        }

        return ['param', ''];
    }

    /**
     * @param list<string> $stack Open elements; changed in place.
     * @return list<array{0: string, 1: string}>
     */
    private static function element(string $piece, string $parameter, array &$stack): array
    {
        if (preg_match('~^<(/?)([a-z][a-z0-9]{0,9})((?:\s+[a-z]+(?:="[^"<>]*")?)*)\s*/?>$~D', $piece, $match) !== 1) {
            throw new CustomTagRejected('mycode_template_invalid');
        }

        [, $closing, $name, $attributes] = $match;

        if (!isset(self::ELEMENTS[$name])) {
            throw new CustomTagRejected('mycode_element_refused', ['element' => $name]);
        }

        if ($closing === '/') {
            if ($attributes !== '' || array_pop($stack) !== $name) {
                throw new CustomTagRejected('mycode_template_unbalanced');
            }

            return [['html', '</' . $name . '>']];
        }

        if (!in_array($name, self::VOID, true)) {
            $stack[] = $name;
        }

        $parts = [['html', '<' . $name]];
        $seen = [];
        preg_match_all('/\s+([a-z]+)(?:="([^"<>]*)")?/', $attributes, $found, PREG_SET_ORDER);

        foreach ($found as $attribute) {
            $key = $attribute[1];
            $value = $attribute[2] ?? null;

            if (!in_array($key, self::ELEMENTS[$name], true) || isset($seen[$key])) {
                throw new CustomTagRejected('mycode_attribute_refused', ['attribute' => $key]);
            }

            $seen[$key] = true;

            foreach (self::attribute($key, $value, $parameter) as $part) {
                $parts[] = $part;
            }
        }

        if ($name === 'a') {
            if (!isset($seen['href'])) {
                throw new CustomTagRejected('mycode_attribute_refused', ['attribute' => 'href']);
            }

            $parts[] = ['html', ' rel="nofollow ugc noopener"'];
        }

        $parts[] = ['html', '>'];

        return $parts;
    }

    /** @return list<array{0: string, 1: string}> */
    private static function attribute(string $key, ?string $value, string $parameter): array
    {
        $decoded = $value === null ? null : html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $valid = match ($key) {
            'class' => $decoded !== null && preg_match(self::CLASSES, $decoded) === 1,
            'dir' => in_array($decoded, ['ltr', 'rtl', 'auto'], true),
            'open' => $decoded === null,
            'title' => $decoded !== null && mb_strlen($decoded) <= 200 && !preg_match('/[\x00-\x1f]/', $decoded),
            'href' => $decoded !== null && (($decoded === '{param}' && $parameter === 'url')
                || ($decoded !== '{param}' && UrlPolicy::allowed($decoded, UrlPolicy::IMAGE_SCHEMES))),
            default => false,
        };

        if (!$valid) {
            throw new CustomTagRejected('mycode_attribute_refused', ['attribute' => $key]);
        }

        if ($decoded === null) {
            return [['html', ' ' . $key]];
        }

        $parts = [['html', ' ' . $key . '="']];

        foreach (preg_split('/(\{param\})/', $decoded, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $piece) {
            if ($piece === '{param}') {
                if ($key !== 'title' && $key !== 'href') {
                    throw new CustomTagRejected('mycode_attribute_refused', ['attribute' => $key]);
                }

                $parts[] = self::param($parameter);
            } elseif ($piece !== '') {
                $parts[] = ['html', self::escape($piece)];
            }
        }

        $parts[] = ['html', '"'];

        return $parts;
    }

    /**
     * @param list<array{0: string, 1: string}> $parts
     * @return list<array{0: string, 1: string}>
     */
    private static function merge(array $parts): array
    {
        $merged = [];

        foreach ($parts as $part) {
            $last = count($merged) - 1;

            if ($part[0] === 'html' && $last >= 0 && $merged[$last][0] === 'html') {
                $merged[$last][1] .= $part[1];
            } else {
                $merged[] = $part;
            }
        }

        return $merged;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
