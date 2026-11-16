<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Infrastructure;

use InvalidArgumentException;
use Nolviko\Modules\Search\Domain\SearchQuery;

/** Backend-owned native full-text expressions. Ranking parity is deliberately not promised. */
final class SearchDialect
{
    public const POSTGRES_VECTOR = "to_tsvector('simple', replace(lower(content), U&'i\\0307', 'i'))";

    public static function predicate(bool $postgres): string
    {
        return $postgres ? "to_tsvector('simple', replace(lower(p.content), U&'i\\0307', 'i')) "
            . "@@ to_tsquery('simple', ?)" : 'MATCH(p.content) AGAINST (? IN BOOLEAN MODE)';
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
