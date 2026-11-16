<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Modules\Community\Infrastructure\ImageProcessor;
use PHPUnit\Framework\TestCase;

/** Image processing is bounded independently of SQL; timings are measurements, not hard time assertions. */
final class CommunityBenchmarkTest extends TestCase
{
    public function testImageCostsAreRecorded(): void
    {
        $image = imagecreatetruecolor(512, 256);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        unset($image);
        $processor = new ImageProcessor();
        $result = BenchmarkHarness::measure(static fn(): array => $processor->process($bytes, true), 30, 3);
        self::assertLessThanOrEqual(134217728, $result['max_peak_bytes']);
        self::assertSame(256, $processor->process($bytes, true)['width']);
        fwrite(STDOUT, 'community_image ' . json_encode(['case' => $result, 'sql' => 0], JSON_THROW_ON_ERROR) . "\n");
    }
}
