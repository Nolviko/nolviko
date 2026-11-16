<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Search;

use Nolviko\Modules\Search\Domain\SearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** User syntax never controls Boolean operators; Unicode and resource bounds are enforced before SQL. */
final class SearchQueryTest extends TestCase
{
    public function testOperatorsAreRebuiltFromWordTokens(): void
    {
        $query = SearchQuery::parse('+ALPHA* -(beta) ~gamma');

        self::assertNotNull($query);
        self::assertSame('+alpha* +beta* +gamma*', $query->boolean());
    }

    public function testTurkishTokensAreNotStripped(): void
    {
        self::assertSame('+güvenli* +arama*', SearchQuery::parse('GÜVENLİ arama')->boolean());
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidOrExpensiveInputIsRejected(string $query): void
    {
        self::assertNull(SearchQuery::parse($query));
    }

    public static function invalidQueries(): array
    {
        return [[''], ['ab'], ["hello\0world"], ["\xff\xfe"], [str_repeat('a', 33)],
            ['one two three four five six seven'], [str_repeat('longword ', 20)]];
    }
}
