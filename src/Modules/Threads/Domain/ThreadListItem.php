<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use DateTimeImmutable;

/**
 * One row of a thread list with the names it shows, loaded by the list query itself (no per-row lookups).
 * A null name means the account no longer exists. `status` is the marker moderators see on deleted threads;
 * `movedToThreadId` marks a redirect stub (M03.08).
 */
final class ThreadListItem
{
    public function __construct(
        public readonly int $id,
        public readonly int $forumId,
        public readonly string $title,
        public readonly string $slug,
        public readonly bool $sticky,
        public readonly bool $locked,
        public readonly ?DateTimeImmutable $lastPostAt,
        public readonly ?string $authorName,
        public readonly ?string $lastPosterName,
        public readonly ThreadStatus $status = ThreadStatus::Visible,
        public readonly ?int $movedToThreadId = null,
        public readonly int $replyCount = 0,
        public readonly int $viewCount = 0,
        public readonly ?int $lastPosterId = null,
    ) {
    }
}
