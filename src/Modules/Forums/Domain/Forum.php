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
    ) {
    }
}
