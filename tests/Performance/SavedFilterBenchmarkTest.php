<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Modules\Admin\Domain\AdminFilterSchema;
use PHPUnit\Framework\TestCase;

/** No connection, expression evaluator or unbounded filter input is needed to restore saved preferences. */
final class SavedFilterBenchmarkTest extends TestCase
{
    public function testBoundedFilterValidation(): void
    {
        $input = ['family' => 'admin', 'actor' => 'Türkçe İ', 'target_type' => 'forum', 'target_id' => '123'];
        self::assertCount(4, AdminFilterSchema::clean('audit', $input));
        $result = BenchmarkHarness::measure(static fn(): array => AdminFilterSchema::clean('audit', $input));
        fwrite(STDERR, 'saved_filter_benchmark ' . json_encode($result, JSON_THROW_ON_ERROR) . "\n");
    }
}
