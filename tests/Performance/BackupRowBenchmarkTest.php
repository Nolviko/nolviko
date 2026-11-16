<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Updates\BackupRowCodec;
use PHPUnit\Framework\TestCase;

final class BackupRowBenchmarkTest extends TestCase
{
    public function testRowRoundTrip(): void
    {
        $values = [null, str_repeat('a', 16384), "\0\xff", '9223372036854775807'];
        $report = BenchmarkHarness::measure(
            static fn(): array => BackupRowCodec::decode(BackupRowCodec::encode($values), count($values)),
            100,
            10,
        );
        self::assertSame($values, BackupRowCodec::decode(BackupRowCodec::encode($values), count($values)));
        fwrite(STDERR, 'backup_row_roundtrip ' . json_encode($report, JSON_THROW_ON_ERROR) . "\n");
    }
}
