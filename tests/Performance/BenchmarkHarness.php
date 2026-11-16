<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use InvalidArgumentException;

/**
 * Minimal in-repo timing harness: wall-time percentiles plus peak memory, no thresholds.
 */
final class BenchmarkHarness
{
    /**
     * `max_peak_bytes`: the process peak while the samples ran (system allocation, as before). `max_added_bytes`
     * (2026-11-14): the most one call allocated on top of what the process already held; a request starts in a
     * fresh PHP process, so this, not the PHPUnit process size, is what a per-request memory budget compares.
     *
     * @return array{samples: int, p50_ms: float, p95_ms: float, max_peak_bytes: int, max_added_bytes: int}
     */
    public static function measure(callable $operation, int $iterations = 100, int $warmup = 10): array
    {
        if ($iterations < 2 || $warmup < 0) {
            throw new InvalidArgumentException('Invalid benchmark sample count');
        }

        for ($i = 0; $i < $warmup; ++$i) {
            $operation();
        }

        $times = [];
        $peak = memory_get_peak_usage(true);
        $added = 0;

        for ($i = 0; $i < $iterations; ++$i) {
            gc_collect_cycles();
            // Reset per call: the peak then belongs to this call, not to earlier tests of the same PHPUnit run.
            memory_reset_peak_usage();
            $before = memory_get_usage();
            $start = hrtime(true);
            $operation();
            $times[] = (hrtime(true) - $start) / 1_000_000;
            $added = max($added, memory_get_peak_usage() - $before);
            $peak = max($peak, memory_get_peak_usage(true));
        }

        sort($times, SORT_NUMERIC);

        return [
            'samples' => $iterations,
            'p50_ms' => self::percentile($times, 0.5),
            'p95_ms' => self::percentile($times, 0.95),
            'max_peak_bytes' => $peak,
            'max_added_bytes' => $added,
        ];
    }

    /**
     * Nearest-rank percentile of sorted samples, rounded to 0.1 microseconds.
     *
     * @param list<float> $sortedTimes
     */
    private static function percentile(array $sortedTimes, float $fraction): float
    {
        $index = (int) ceil($fraction * count($sortedTimes)) - 1;

        return round($sortedTimes[max(0, $index)], 4);
    }
}
