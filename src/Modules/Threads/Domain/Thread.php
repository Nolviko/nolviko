<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

/**
 * A stored thread row. `replyCount` counts visible posts after the first one (M03.05). `movedToThreadId` marks
 * a redirect stub left by a move (M03.08): it has no posts and takes no replies.
 */
final class Thread
{
    public function __construct(
        public readonly int $id,
        public readonly int $forumId,
        public readonly ?int $userId,
        public readonly string $title,
        public readonly string $slug,
        public readonly ThreadStatus $status,
        public readonly bool $isLocked,
        public readonly bool $isSticky,
        public readonly int $replyCount,
        public readonly ?int $firstPostId,
        public readonly ?int $lastPostId,
        public readonly ?int $movedToThreadId = null,
    ) {
    }
}
