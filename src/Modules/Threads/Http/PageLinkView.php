<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use Nolviko\Core\View\ViewModel;

/**
 * One entry of a thread page's pagination: a page number with its canonical URL, or a gap (`number` 0, no URL).
 */
final readonly class PageLinkView implements ViewModel
{
    public function __construct(
        public int $number,
        public ?string $url,
        public bool $current,
    ) {
    }
}
