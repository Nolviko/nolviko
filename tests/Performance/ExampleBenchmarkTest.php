<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('benchmark')]
final class ExampleBenchmarkTest extends TestCase
{
    public function testHarnessReportsSamples(): void
    {
        $report = BenchmarkHarness::measure(
            static function (): void {
                hash('sha256', 'example fixture');
            },
            iterations: 50,
            warmup: 5,
        );

        self::assertSame(50, $report['samples']);
        self::assertGreaterThanOrEqual(0.0, $report['p50_ms']);
        self::assertGreaterThanOrEqual($report['p50_ms'], $report['p95_ms']);
        fwrite(STDERR, 'Benchmark illustration: ' . json_encode($report) . PHP_EOL);
        // No wall-time threshold here: environment-specific baselines live in CI reports.
    }
}
