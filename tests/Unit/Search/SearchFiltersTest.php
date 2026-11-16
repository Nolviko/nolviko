<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Search;

use InvalidArgumentException;
use Nolviko\Modules\Search\Domain\SearchFilters;
use Nolviko\Modules\Search\Domain\SearchQuery;
use Nolviko\Modules\Search\Infrastructure\SearchDialect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Typed search bounds, native query construction and PostgreSQL expression/index agreement. */
final class SearchFiltersTest extends TestCase
{
    #[DataProvider('invalidFilters')]
    public function testRejectsMalformedFilters(array $fields): void
    {
        $this->expectException(InvalidArgumentException::class);
        SearchFilters::parse($fields);
    }

    public static function invalidFilters(): array
    {
        return [[['author' => '-1']], [['forum' => '1 OR 1=1']], [['forum' => []]],
            [['from' => '2026-02-30']], [['until' => '2026-1-01']],
            [['from' => '2026-11-05', 'until' => '2026-11-04']], [['from' => []]]];
    }

    public function testValidFiltersAndNativeQueriesKeepUserOperatorsOut(): void
    {
        $filters = SearchFilters::parse(['forum' => '4', 'author' => '3', 'from' => '2026-11-01']);
        self::assertSame(4, $filters->forum);
        self::assertSame(['forum' => 4, 'author' => 3, 'from' => '2026-11-01'], $filters->query());
        $query = SearchQuery::parse('+alpha -bravo');
        self::assertSame('+alpha* +bravo*', SearchDialect::value($query, false));
        self::assertSame('alpha:* & bravo:*', SearchDialect::value($query, true));
        self::assertStringContainsString("U&'i\\0307'", SearchDialect::POSTGRES_VECTOR);
        self::assertStringContainsString('USING GIN', SearchDialect::indexStatements('postgresql')[0]);
        self::assertStringContainsString('FULLTEXT', SearchDialect::indexStatements('mysql')[0]);
    }
}
