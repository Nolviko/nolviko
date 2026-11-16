<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;

final class BackupChunkBenchmarkTest extends TestCase
{
    public function testVerifiedChunkRead(): void
    {
        $root = new TemporaryDirectory();
        chmod($root->path, 0700);
        mkdir($root->path . '/public');

        try {
            $store = new BackupChunkStore($root->path, $root->path . '/public');
            $id = str_repeat('c', 32);
            $bytes = str_repeat('x', 65536);
            $digest = $store->put($id, $bytes);
            $report = BenchmarkHarness::measure(static fn(): string => $store->read($id, $digest), 100, 10);
            self::assertSame($bytes, $store->read($id, $digest));
            fwrite(STDERR, 'backup_chunk_read ' . json_encode($report, JSON_THROW_ON_ERROR) . "\n");
        } finally {
            $root->remove();
        }
    }
}
