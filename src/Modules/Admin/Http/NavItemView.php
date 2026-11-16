<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\View\ViewModel;

/**
 * One admin sidebar link with its translated label; `current` marks the page shown (`aria-current`).
 */
final readonly class NavItemView implements ViewModel
{
    public function __construct(
        public string $label,
        public string $url,
        public bool $current,
        public string $group = '',
    ) {
    }
}
