<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Stringable;

/**
 * HTML produced by a trusted renderer (post formatter, slot renderer) that templates print without escaping.
 *
 * This is the only way to output markup: the `raw` filter and `autoescape false` are rejected at compile time
 * (TemplatePolicy). Never wrap user input here; only code that builds the HTML itself and escapes every value
 * it inserts may create one. Print it only as element content: Twig does not know the output context, so in
 * an attribute value it would not be escaped either.
 */
final readonly class SafeHtml implements Stringable
{
    public function __construct(
        public string $html,
    ) {
    }

    public function __toString(): string
    {
        return $this->html;
    }
}
