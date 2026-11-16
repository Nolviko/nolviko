<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Domain;

use Nolviko\Modules\Permissions\Domain\ForumScope;

/** All engines must filter current visibility before counts/page cuts and return plain source excerpts. */
interface PostSearch
{
    /** @return array{rows: list<array<string, mixed>>, count: int, capped: bool, next: ?int, partial?: bool} */
    public function find(
        ForumScope $scope,
        SearchQuery $query,
        ?int $before = null,
        ?SearchFilters $filters = null
    ): array;
}
