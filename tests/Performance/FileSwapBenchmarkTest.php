<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Updates\ResumableFileSwap;
use Nolviko\Tests\Support\FileSwitchFixture;
use PHPUnit\Framework\TestCase;

final class FileSwapBenchmarkTest extends TestCase
{
    public function testBoundedFilePromotion(): void
    {
        $fixture = new FileSwitchFixture([], ['public/a.bin' => str_repeat('x', 32768)]);

        try {
            $plan = $fixture->plan();
            $runner = new ResumableFileSwap($fixture->live, $fixture->private);
            $report = BenchmarkHarness::measure(static function () use ($runner, $plan, $fixture): void {
                $target = $fixture->live . '/public/a.bin';

                if (is_file($target)) {
                    unlink($target);
                }

                $runner->step($plan, bin2hex(random_bytes(16)), static function (): void {
                });
            }, 100, 10);
            self::assertSame(32768, filesize($fixture->live . '/public/a.bin'));
            fwrite(STDERR, 'file_swap ' . json_encode($report, JSON_THROW_ON_ERROR) . "\n");
        } finally {
            $fixture->root->remove();
        }
    }
}
