<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Domain;

use DateTimeImmutable;

/**
 * One row of the board index (M03.09): a category or forum in tree order with its stored counters and its last
 * post. The last-post fields are null when the forum has none or the reader may not read its threads.
 */
final class ForumIndexEntry
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly ForumType $type,
        public readonly string $title,
        public readonly string $slug,
        public readonly int $depth,
        public readonly int $threadCount,
        public readonly int $postCount,
        public readonly ?DateTimeImmutable $lastPostAt,
        public readonly ?int $lastThreadId,
        public readonly ?string $lastThreadTitle,
        public readonly ?string $lastPosterName,
        public readonly ?string $description = null,
        public readonly ?string $lastThreadSlug = null,
    ) {
    }
}
