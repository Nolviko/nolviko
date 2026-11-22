<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Domain;

use Nolviko\Modules\Permissions\Domain\ForumScope;

/**
 * All engines must filter current visibility before counts/page cuts and return plain source excerpts.
 *
 * `$before` is the page cursor: a post ID for newest first (rows below it), a post ID for oldest first (rows
 * above it) and the number of rows already shown for relevance (beta.18). With `SearchFilters::$titles` the rows
 * are threads (`kind` = `thread`, `id` = thread ID, plus `slug`); otherwise posts (`kind` = `post`).
 */
interface PostSearch
{
    /** Relevance pages stop here: the score order is taken over the newest matches only. */
    public const RELEVANCE_WINDOW = 1000;

    /** @return array{rows: list<array<string, mixed>>, count: int, capped: bool, next: ?int, partial?: bool} */
    public function find(
        ForumScope $scope,
        SearchQuery $query,
        ?int $before = null,
        ?SearchFilters $filters = null
    ): array;

    /**
     * The orders this engine can give (beta.18, roadmap 26), from SearchFilters::SORTS; newest is always one.
     *
     * @return list<string>
     */
    public function sorts(): array;
}
