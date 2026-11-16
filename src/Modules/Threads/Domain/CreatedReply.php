<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use Nolviko\Modules\Posts\Domain\PostStatus;

/**
 * The result of a reply. `repeated` is true when the form token had already been used: nothing was written and
 * `postId` is the post the first submit created.
 */
final class CreatedReply
{
    public function __construct(
        public readonly int $threadId,
        public readonly int $postId,
        public readonly PostStatus $status,
        public readonly bool $repeated = false,
    ) {
    }
}
