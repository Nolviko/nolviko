<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

/**
 * The result of a committed thread creation.
 */
final class CreatedThread
{
    public function __construct(
        public readonly int $threadId,
        public readonly int $postId,
        public readonly string $slug,
        public readonly ThreadStatus $status,
    ) {
    }
}
