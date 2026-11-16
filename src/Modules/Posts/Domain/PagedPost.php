<?php

declare(strict_types=1);

namespace Nolviko\Modules\Posts\Domain;

/**
 * A post of a thread page with its author's name, loaded by the page query itself (no per-post lookups). A null
 * name means the account no longer exists.
 */
final class PagedPost
{
    public function __construct(
        public readonly Post $post,
        public readonly ?string $authorName,
    ) {
    }
}
