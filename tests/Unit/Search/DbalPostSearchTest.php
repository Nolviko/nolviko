<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Search;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Search\Domain\SearchFilters;
use Nolviko\Modules\Search\Domain\SearchQuery;
use Nolviko\Modules\Search\Infrastructure\DbalPostSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The authorized first page can supply its total, while paginated searches still count all authorized matches. */
final class DbalPostSearchTest extends TestCase
{
    public function testForbiddenForumSkipsTheDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('getDatabasePlatform');
        $query = SearchQuery::parse('performance');
        self::assertNotNull($query);

        $result = (new DbalPostSearch($connection))->find(
            new ForumScope([2]),
            $query,
            filters: new SearchFilters(forum: 3),
        );

        self::assertSame(['rows' => [], 'count' => 0, 'capped' => false, 'next' => null], $result);
    }

    #[DataProvider('pages')]
    public function testCountsUseTheFirstPageOrAnUnpaginatedQuery(
        int $rows,
        ?int $cursor,
        ?int $databaseCount,
        string $sort,
    ): void {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturn(
            array_fill(0, $rows, ['id' => 7, 'head' => 'Excerpt']),
        );

        if ($databaseCount === null) {
            $connection->expects(self::never())->method('fetchOne');
        } else {
            $connection->expects(self::once())->method('fetchOne')->with(
                self::stringContains('LIMIT 1001'),
                [2, '+performance*'],
            )->willReturn($databaseCount);
        }

        $query = SearchQuery::parse('performance');
        self::assertNotNull($query);

        $result = (new DbalPostSearch($connection))->find(
            new ForumScope([2, 3]),
            $query,
            $cursor,
            new SearchFilters(forum: 2, sort: $sort),
        );

        self::assertCount(min(20, $rows), $result['rows']);
        self::assertSame(min(1000, $databaseCount ?? $rows), $result['count']);
        self::assertSame(($databaseCount ?? $rows) > 1000, $result['capped']);
    }

    public static function pages(): array
    {
        return [
            'no matches' => [0, null, null, SearchFilters::NEWEST],
            'full page without continuation' => [20, null, null, SearchFilters::NEWEST],
            'first page with continuation' => [21, null, 1001, SearchFilters::OLDEST],
            'last page still counts earlier matches' => [3, 34, 23, SearchFilters::NEWEST],
            'relevance count excludes rank and offset arguments' => [3, 20, 23, SearchFilters::RELEVANCE],
        ];
    }
}
