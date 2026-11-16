<?php

declare(strict_types=1);

namespace Nolviko\Modules\Editor\Domain;

/**
 * Syntax tree → HTML, exactly the elements and attributes of the ADR-012 tag table; every piece of text is
 * escaped. Bump VERSION whenever the output for any input changes: cached renderings of older versions are then
 * re-rendered lazily on read (PostRenderer).
 *
 * The output is locale-independent so one cached copy serves every reader. The only UI text a post needs, the
 * label of an untitled spoiler, is written as a placeholder that `localize()` replaces per request; the
 * placeholder's private-use character is removed from user text, so a post cannot fake one.
 */
final class BbcodeRenderer
{
    /** 2 (2026-11-08): a bare YouTube or Vimeo link alone on its line becomes a MediaEmbed card. */
    public const VERSION = 2;

    /** Replaced by the translated "Spoiler" label at display time. */
    public const SPOILER_LABEL = "\u{E000}spoiler\u{E000}";

    private const MARKER = "\u{E000}";

    private const SIMPLE = [
        'b' => ['<strong>', '</strong>'],
        'i' => ['<em>', '</em>'],
        'u' => ['<span class="bb-u">', '</span>'],
        's' => ['<del>', '</del>'],
        'tr' => ['<tr>', '</tr>'],
        'th' => ['<th>', '</th>'],
        'td' => ['<td>', '</td>'],
        '*' => ['<li>', '</li>'],
    ];

    /** Bare URLs in text; trailing punctuation is left outside the link. */
    private const BARE_URL = '~https?://[^\s<>"\'\[\]`\\\\]+(?<![.,!?;:)])~i';

    public function __construct(
        private readonly BbcodeParser $parser = new BbcodeParser(),
    ) {
    }

    public function render(string $source): string
    {
        return $this->children($this->parser->parse($source), autolink: true);
    }

    /**
     * Fills the per-request text into a cached rendering.
     */
    public static function localize(string $html, string $spoilerLabel): string
    {
        return str_replace(self::SPOILER_LABEL, self::escape($spoilerLabel), $html);
    }

    private function children(BbcodeNode $node, bool $autolink): string
    {
        $html = '';

        foreach ($node->children as $child) {
            $html .= $child->isText() ? self::text($child->text, $autolink) : $this->element($child, $autolink);
        }

        return $html;
    }

    private function element(BbcodeNode $node, bool $autolink): string
    {
        $tag = (string) $node->tag;

        if (isset(self::SIMPLE[$tag])) {
            return self::SIMPLE[$tag][0] . $this->children($node, $autolink) . self::SIMPLE[$tag][1];
        }

        return match ($tag) {
            'url' => $this->link($node),
            'img' => '<img src="' . self::escape($node->text) . '" alt="" loading="lazy" '
                . 'referrerpolicy="no-referrer" class="bb-img">',
            'code' => '<pre class="bb-code"><code' . self::languageClass($node->parameter) . '>'
                . self::escape(self::clean($node->text)) . '</code></pre>',
            'quote' => '<blockquote class="bb-quote">'
                . ($node->parameter === null ? '' : '<cite>' . self::escape(self::clean($node->parameter)) . '</cite>')
                . $this->children($node, $autolink) . '</blockquote>',
            'spoiler' => '<details class="bb-spoiler"><summary>'
                . ($node->parameter === null ? self::SPOILER_LABEL : self::escape(self::clean($node->parameter)))
                . '</summary>' . $this->children($node, $autolink) . '</details>',
            'list' => ($node->parameter === '1' ? '<ol>' : '<ul>') . $this->children($node, $autolink)
                . ($node->parameter === '1' ? '</ol>' : '</ul>'),
            'color' => '<span class="bb-color-' . strtolower((string) $node->parameter) . '">'
                . $this->children($node, $autolink) . '</span>',
            'size' => '<span class="bb-size-' . strtolower((string) $node->parameter) . '">'
                . $this->children($node, $autolink) . '</span>',
            'table' => '<table class="bb-table">' . $this->children($node, $autolink) . '</table>',
            default => $this->children($node, $autolink),
        };
    }

    private function link(BbcodeNode $node): string
    {
        $href = $node->parameter ?? $node->text;
        $label = $node->parameter === null ? self::text($node->text, false) : $this->children($node, false);

        return '<a href="' . self::escape($href) . '" rel="nofollow ugc noopener">'
            . ($label === '' ? self::escape($href) : $label) . '</a>';
    }

    /** Only whitespace between the link and the line's start and end. */
    private static function aloneOnLine(string $text, int $position, int $length): bool
    {
        $start = strrpos(substr($text, 0, $position), "\n");
        $before = substr($text, $start === false ? 0 : $start + 1, $position - ($start === false ? 0 : $start + 1));
        $rest = substr($text, $position + $length);
        $end = strpos($rest, "\n");

        return trim($before) === '' && trim($end === false ? $rest : substr($rest, 0, $end)) === '';
    }

    private static function text(string $text, bool $autolink): string
    {
        $text = self::clean($text);

        if (!$autolink) {
            return nl2br(self::escape($text), false);
        }

        $html = '';
        $offset = 0;
        preg_match_all(self::BARE_URL, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$url, $position]) {
            $html .= nl2br(self::escape(substr($text, $offset, $position - $offset)), false);
            $video = UrlPolicy::allowed($url) && self::aloneOnLine($text, $position, strlen($url))
                ? MediaEmbed::match($url) : null;

            if ($video !== null) {
                $html .= MediaEmbed::html(...$video);
                $offset = $position + strlen($url);
                continue;
            }

            $html .= UrlPolicy::allowed($url)
                ? '<a href="' . self::escape($url) . '" rel="nofollow ugc noopener">' . self::escape($url) . '</a>'
                : self::escape($url);
            $offset = $position + strlen($url);
        }

        return $html . nl2br(self::escape(substr($text, $offset)), false);
    }

    private static function languageClass(?string $language): string
    {
        return $language === null ? '' : ' class="language-' . self::escape(strtolower($language)) . '"';
    }

    /**
     * Removes the placeholder marker and invalid UTF-8 from user text.
     */
    private static function clean(string $text): string
    {
        $valid = mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');

        return str_replace(self::MARKER, "\u{FFFD}", $valid);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
