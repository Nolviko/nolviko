<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Node\AutoEscapeNode;
use Twig\Node\Expression\Filter\RawFilter;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

/**
 * Compile-time rules for every template: no `raw` filter and no `{% autoescape false %}` (other strategies,
 * such as `'js'`, stay allowed).
 *
 * Runs before Twig's escaper (lowest priority), so it sees only what the template author wrote. Markup
 * reaches the page only as SafeHtml from a trusted renderer.
 */
final class TemplatePolicy implements NodeVisitorInterface
{
    public const RAW_FORBIDDEN = 'template_raw_forbidden';

    public const AUTOESCAPE_FORBIDDEN = 'template_autoescape_forbidden';

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof RawFilter) {
            throw new SyntaxError(self::RAW_FORBIDDEN, $node->getTemplateLine(), $node->getSourceContext());
        }

        if ($node instanceof AutoEscapeNode && $node->getAttribute('value') === false) {
            throw new SyntaxError(self::AUTOESCAPE_FORBIDDEN, $node->getTemplateLine(), $node->getSourceContext());
        }

        return $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        return $node;
    }

    public function getPriority(): int
    {
        return -10;
    }
}
