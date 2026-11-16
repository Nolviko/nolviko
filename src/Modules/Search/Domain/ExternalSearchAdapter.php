<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Domain;

/** Optional external engines return candidate IDs only. Core must reauthorize canonical rows before output. */
interface ExternalSearchAdapter
{
    /** @return list<int> At most100 bounded candidate IDs, no snippets/counts trusted from the external service. */
    public function candidates(SearchQuery $query, SearchFilters $filters, ?int $before): array;
}
