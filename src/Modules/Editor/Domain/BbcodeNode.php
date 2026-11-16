<?php

declare(strict_types=1);

namespace Nolviko\Modules\Editor\Domain;

/**
 * A node of the BBCode syntax tree: literal text (`tag` null, `text` set) or an element with an optional
 * parameter and children. Built only by BbcodeParser, which guarantees the ADR-012 rules (allowed tags,
 * valid parameters and structure, limits).
 */
final class BbcodeNode
{
    /**
     * @param list<BbcodeNode> $children
     */
    public function __construct(
        public readonly ?string $tag,
        public readonly string $text = '',
        public readonly ?string $parameter = null,
        public array $children = [],
    ) {
    }

    public static function text(string $text): self
    {
        return new self(null, $text);
    }

    public function isText(): bool
    {
        return $this->tag === null;
    }
}
