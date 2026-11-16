<?php

declare(strict_types=1);

namespace Nolviko\Modules\Posts\Domain;

use Closure;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Modules\Community\Domain\Mentions;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;

/**
 * A post's HTML for templates (ADR-012): the cached rendering when it was produced by the current renderer
 * version, otherwise rendered now and written back for the next reader (lazy, per post). The per-request
 * label of untitled spoilers is filled in afterwards, so the cache stays locale-independent. Censored words
 * (WordCensor, 2026-11-08) are masked on output too, so the stored rendering never depends on the list.
 */
final class PostRenderer
{
    public const FORMAT = 'bbcode';

    public function __construct(
        private readonly PostRepository $posts,
        private readonly BbcodeRenderer $renderer = new BbcodeRenderer(),
        private readonly ?Closure $censoredWords = null,
        private readonly ?Closure $mentionUrl = null,
    ) {
    }

    public function html(Post $post, string $spoilerLabel): SafeHtml
    {
        $cached = $post->rendererVersion === BbcodeRenderer::VERSION ? $post->renderedHtml : null;

        if ($cached === null) {
            $cached = $this->renderer->render($post->content);
            $this->posts->storeRendering($post->id, $cached, BbcodeRenderer::VERSION, $post->content);
        }

        $html = BbcodeRenderer::localize($cached, $spoilerLabel);
        $words = $this->censoredWords === null ? [] : ($this->censoredWords)();

        $html = is_array($words) && $words !== [] ? WordCensor::html($html, $words) : $html;

        return new SafeHtml($this->mentionUrl === null ? $html : Mentions::link($html, $this->mentionUrl));
    }
}
