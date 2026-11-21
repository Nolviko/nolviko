<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Infrastructure;

use InvalidArgumentException;
use Nolviko\Modules\Search\Domain\SearchQuery;

/** Backend-owned native full-text expressions. Ranking parity is deliberately not promised. */
final class SearchDialect
{
    public const POSTGRES_VECTOR = "to_tsvector('simple', replace(lower(content), U&'i\0307', 'i'))";

    /** Thread titles (beta.18, migration 0065): the same folding as post bodies. */
    public const POSTGRES_TITLE_VECTOR = "to_tsvector('simple', replace(lower(title), U&'i\0307', 'i'))";

    public static function predicate(bool $postgres): string
    {
        return self::match($postgres, 'p.content');
    }

    /**
     * The full-text condition on `p.content` or `t.title`; each needs its own index (0033, 0065).
     */
    public static function match(bool $postgres, string $column): string
    {
        return $postgres ? "to_tsvector('simple', replace(lower(" . $column . "), U&'i\0307', 'i')) "
            . "@@ to_tsquery('simple', ?)" : 'MATCH(' . $column . ') AGAINST (? IN BOOLEAN MODE)';
    }

    /**
     * The engine's own relevance score for the same expression, bound with the same value (beta.18, roadmap 26).
     */
    public static function score(bool $postgres, string $column): string
    {
        return $postgres ? "ts_rank(to_tsvector('simple', replace(lower(" . $column . "), U&'i\0307', 'i')), "
            . "to_tsquery('simple', ?))" : 'MATCH(' . $column . ') AGAINST (? IN BOOLEAN MODE)';
    }

    public static function value(SearchQuery $query, bool $postgres): string
    {
        return $postgres ? implode(' & ', array_map(static fn(string $term): string => $term . ':*', $query->terms))
            : $query->boolean();
    }

    public static function indexStatements(string $backend): array
    {
        return match ($backend) {
            'mysql', 'mariadb' => ['CREATE FULLTEXT INDEX ft_posts_content ON posts (content)'],
            'postgresql' => ['CREATE INDEX ft_posts_content ON posts USING GIN (' . self::POSTGRES_VECTOR . ')'],
            default => throw new InvalidArgumentException('search_backend_unsupported'),
        };
    }
}
