<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

/**
 * Renders a logical template ID (`layouts/page.twig`) through the active theme chain.
 */
interface TemplateRenderer
{
    /**
     * @param array<string, mixed> $context Scalars, arrays, SafeHtml and readonly ViewModel objects only.
     *
     * @throws TemplateException `template_missing:<name>` when no theme and no core template has it, or
     *     `template_name_invalid:<name>` / `template_outside_roots:<name>`, `view_context_invalid:<path>`, or
     *     `template_raw_forbidden:<name>` / `template_autoescape_forbidden:<name>`.
     */
    public function render(string $name, array $context = []): string;
}
