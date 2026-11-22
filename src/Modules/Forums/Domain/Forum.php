<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Domain;

/**
 * A stored forum or category. `depth` is 0 for roots and is kept in step with the tree on every move. A locked
 * forum takes no new threads except from subjects with `moderation.thread.lock` (M03.04).
 */
final class Forum
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly ForumType $type,
        public readonly string $title,
        public readonly string $slug,
        public readonly int $sortOrder,
        public readonly int $depth,
        public readonly bool $isLocked = false,
        public readonly ?string $description = null,
        /** Question-and-answer forum: replies can be accepted as the answer (2026-11-14). */
        public readonly bool $questions = false,
        /** Link section target (beta.18, ForumType::Link only). */
        public readonly ?string $linkUrl = null,
        /** Count clicks on the link section. */
        public readonly bool $linkCounts = false,
        public readonly int $linkClicks = 0,
        /** Posts here raise the member post count, ranks and promotion (beta.18, PostCountRule). */
        public readonly bool $countsPosts = true,
        /** A forum password is set (ADR-041); the hash itself never leaves the repository. */
        public readonly bool $hasPassword = false,
        /** Guests may post here when the guest group has the posting permissions (ADR-040). */
        public readonly bool $guestPosting = false,
    ) {
    }
}
