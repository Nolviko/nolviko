<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Core\Updates\FileBackup;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class BackupFilesBenchmarkTest extends TestCase
{
    public function testPrivateFileSnapshot(): void
    {
        $root = new TemporaryDirectory();
        mkdir($root->path . '/public');
        mkdir($root->path . '/private', 0700);
        $root->write('fixture.bin', str_repeat('x', 32768));

        try {
            $store = new BackupChunkStore($root->path . '/private', $root->path . '/public');
            $report = BenchmarkHarness::measure(static fn(): array => FileBackup::export(
                $store,
                $root->path,
                ['fixture.bin'],
                static function (): void {
                },
            ), 100, 10);
            self::assertGreaterThan(0, $report['p50_ms']);
            fwrite(STDERR, 'backup_files_snapshot ' . json_encode($report, JSON_THROW_ON_ERROR) . "\n");
        } finally {
            $root->remove();
        }
    }
}
