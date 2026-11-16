<?php

declare(strict_types=1);

namespace Nolviko\Modules\Editor\Domain;

/**
 * BBCode source → syntax tree, following ADR-012. Single pass with a stack of open elements:
 *
 * - Only allowed tags with valid parameters open elements; anything else stays literal text, character for
 *   character, so nothing typed is ever lost or reinterpreted.
 * - `code`, `url` (without parameter) and `img` read their content raw up to the matching closer.
 * - A closer closes every element above its opener (mis-nesting); a closer without opener is text; open
 *   elements are closed at the end.
 * - List items, table rows and cells are created where the structure needs them, so the tree always
 *   renders as valid HTML.
 * - Limits: nesting depth, quote depth and the number of tags; tags beyond them are literal text.
 */
final class BbcodeParser
{
    public const MAX_DEPTH = 20;

    public const MAX_QUOTE_DEPTH = 5;

    public const MAX_TAGS = 1000;

    public const COLORS = ['black', 'white', 'gray', 'silver', 'red', 'maroon', 'orange', 'yellow', 'olive', 'lime',
        'green', 'teal', 'aqua', 'blue', 'navy', 'purple'];

    public const SIZES = ['small', 'large', 'x-large'];

    private const TAGS = ['b', 'i', 'u', 's', 'url', 'quote', 'code', 'list', '*', 'img', 'spoiler', 'color', 'size',
        'table', 'tr', 'th', 'td'];

    /** Tags whose content is read raw, up to the matching closer. */
    private const RAW = ['code', 'img'];

    private const TAG_PATTERN = '/\[(\/?)([a-z]+|\*)(?:=([^\]\r\n]{0,500}))?\]/i';

    private const CODE_LANGUAGE = '/^[a-z0-9+#-]{1,20}$/Di';

    private const NAME = '/^[^\[\]\r\n]{1,100}$/Du';

    /** @var list<BbcodeNode> */
    private array $stack = [];

    private int $tags = 0;

    public function parse(string $source): BbcodeNode
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $root = new BbcodeNode('root');
        $this->stack = [$root];
        $this->tags = 0;
        $offset = 0;
        $length = strlen($source);

        while ($offset < $length) {
            if (preg_match(self::TAG_PATTERN, $source, $match, PREG_OFFSET_CAPTURE, $offset) !== 1) {
                $this->appendText(substr($source, $offset));

                break;
            }

            [$whole, $position] = $match[0];
            $this->appendText(substr($source, $offset, $position - $offset));
            $offset = $position + strlen($whole);
            $tag = strtolower($match[2][0]);
            $parameter = isset($match[3]) && $match[3][1] !== -1 ? trim($match[3][0], " \"'") : null;

            if ($match[1][0] === '/') {
                $this->close($tag, $whole);
            } else {
                $offset = $this->open($tag, $parameter, $whole, $source, $offset);
            }
        }

        return $root;
    }

    /**
     * @return int The offset to continue from (after raw content for raw tags).
     */
    private function open(string $tag, ?string $parameter, string $whole, string $source, int $offset): int
    {
        $allowed = in_array($tag, self::TAGS, true) && $this->withinLimits($tag);

        if (!$allowed || !$this->validParameter($tag, $parameter)) {
            $this->appendText($whole);

            return $offset;
        }

        if (in_array($tag, self::RAW, true) || ($tag === 'url' && $parameter === null)) {
            return $this->openRaw($tag, $parameter, $whole, $source, $offset);
        }

        if (!$this->placeStructure($tag)) {
            $this->appendText($whole);

            return $offset;
        }

        $this->push(new BbcodeNode($tag, '', $parameter));
        ++$this->tags;

        return $offset;
    }

    private function openRaw(string $tag, ?string $parameter, string $whole, string $source, int $offset): int
    {
        $closer = stripos($source, '[/' . $tag . ']', $offset);
        $content = $closer === false ? substr($source, $offset) : substr($source, $offset, $closer - $offset);
        $end = $closer === false ? strlen($source) : $closer + strlen($tag) + 3;
        $valid = match ($tag) {
            'img' => UrlPolicy::allowed(trim($content), UrlPolicy::IMAGE_SCHEMES),
            'url' => UrlPolicy::allowed(trim($content)),
            default => true,
        };

        if (!$valid || !$this->placeStructure($tag)) {
            $this->appendText($whole);

            return $offset;
        }

        $this->top()->children[] = new BbcodeNode($tag, $tag === 'code' ? $content : trim($content), $parameter);
        ++$this->tags;

        return $end;
    }

    private function close(string $tag, string $whole): void
    {
        for ($index = count($this->stack) - 1; $index > 0; --$index) {
            if ($this->stack[$index]->tag === $tag) {
                $this->stack = array_slice($this->stack, 0, $index);

                return;
            }
        }

        $this->appendText($whole);
    }

    private function appendText(string $text): void
    {
        if ($text === '') {
            return;
        }

        // Whitespace directly in a list or table is only layout; other text gets an item or cell first.
        if (in_array($this->top()->tag, ['list', 'table', 'tr'], true)) {
            if (trim($text) === '') {
                return;
            }

            $this->ensureCell();
        }

        $this->top()->children[] = BbcodeNode::text($text);
    }

    /**
     * Makes the stack fit the tag: list items go into the nearest list, rows into the nearest table, cells into
     * the nearest row (creating it when needed), closing inline elements in between but never crossing a
     * quote, spoiler or other container. Returns false when the tag cannot be placed (an item outside a
     * list, a nested table).
     */
    private function placeStructure(string $tag): bool
    {
        if ($tag === 'table') {
            return !$this->isOpen('table');
        }

        $containers = match ($tag) {
            '*' => ['list'],
            'tr' => ['table'],
            'th', 'td' => ['tr', 'table'],
            default => null,
        };

        if ($containers === null) {
            return $this->ensureCell();
        }

        if (!$this->closeTo($containers)) {
            return false;
        }

        if (($tag === 'th' || $tag === 'td') && $this->top()->tag === 'table') {
            $this->push(new BbcodeNode('tr'));
        }

        return true;
    }

    /**
     * Ordinary content directly inside a list, table or row first gets an item or cell.
     */
    private function ensureCell(): bool
    {
        $top = $this->top()->tag;

        if ($top === 'list') {
            $this->push(new BbcodeNode('*'));
        } elseif ($top === 'table' || $top === 'tr') {
            if ($top === 'table') {
                $this->push(new BbcodeNode('tr'));
            }

            $this->push(new BbcodeNode('td'));
        }

        return true;
    }

    /**
     * Pops up to the nearest element whose tag is in $containers, if no block boundary lies in between.
     *
     * @param list<string> $containers
     */
    private function closeTo(array $containers): bool
    {
        for ($index = count($this->stack) - 1; $index > 0; --$index) {
            $tag = $this->stack[$index]->tag;

            if (in_array($tag, $containers, true)) {
                $this->stack = array_slice($this->stack, 0, $index + 1);

                return true;
            }

            if (in_array($tag, ['quote', 'spoiler', 'list', 'table'], true)) {
                return false;
            }
        }

        return false;
    }

    private function push(BbcodeNode $node): void
    {
        $this->top()->children[] = $node;
        $this->stack[] = $node;
    }

    private function withinLimits(string $tag): bool
    {
        if ($this->tags >= self::MAX_TAGS || count($this->stack) > self::MAX_DEPTH) {
            return false;
        }

        if ($tag !== 'quote') {
            return true;
        }

        $quotes = count(array_filter($this->stack, static fn(BbcodeNode $node): bool => $node->tag === 'quote'));

        return $quotes < self::MAX_QUOTE_DEPTH;
    }

    private function validParameter(string $tag, ?string $parameter): bool
    {
        return match ($tag) {
            'url' => $parameter === null || UrlPolicy::allowed($parameter),
            'quote', 'spoiler' => $parameter === null || preg_match(self::NAME, $parameter) === 1,
            'code' => $parameter === null || preg_match(self::CODE_LANGUAGE, $parameter) === 1,
            'list' => $parameter === null || $parameter === '1',
            'color' => $parameter !== null && in_array(strtolower($parameter), self::COLORS, true),
            'size' => $parameter !== null && in_array(strtolower($parameter), self::SIZES, true),
            default => $parameter === null,
        };
    }

    private function isOpen(string $tag): bool
    {
        foreach ($this->stack as $node) {
            if ($node->tag === $tag) {
                return true;
            }
        }

        return false;
    }

    private function top(): BbcodeNode
    {
        return $this->stack[count($this->stack) - 1];
    }
}
