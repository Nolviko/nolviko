<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * The set of forums a subject may see (M02.10). Queries for lists, counts, latest posts, previews and search
 * apply it in SQL through `Infrastructure\ScopeSql`, before `LIMIT` and `COUNT`, so a hidden forum does not
 * exist for that query. An empty set matches nothing.
 */
final class ForumScope
{
    /**
     * @param list<int> $forumIds
     */
    public function __construct(
        public readonly array $forumIds,
    ) {
    }

    public function contains(int $forumId): bool
    {
        return in_array($forumId, $this->forumIds, true);
    }
}
