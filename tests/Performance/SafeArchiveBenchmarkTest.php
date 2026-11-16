<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Updates\SafeReleaseArchive;
use Nolviko\Tests\Support\ReleaseSigningFixture;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Bounded private extraction including verification, streaming, file syncs and its durable cursor.
 */
#[Group('benchmark')]
final class SafeArchiveBenchmarkTest extends TestCase
{
    public function testTwentyFileExtractionBudget(): void
    {
        $root = new TemporaryDirectory();
        $root->write('private/.keep', '');
        $root->write('public/.keep', '');
        $source = $root->path . '/source.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($source, ZipArchive::CREATE | ZipArchive::EXCL));
        $payload = str_repeat('body { color: red; }', 256);

        for ($index = 0; $index < 20; ++$index) {
            self::assertTrue($zip->addFromString('src/file-' . $index . '.css', $payload), (string) $index);
        }

        self::assertTrue($zip->close());
        $manifest = ReleaseSigningFixture::verify(ReleaseSigningFixture::body([
            'archive_bytes' => filesize($source),
            'archive_sha256' => hash_file('sha256', $source),
            'file_count' => 20,
            'unpacked_bytes' => 20 * strlen($payload),
        ]));
        $extractor = new SafeReleaseArchive($root->path . '/private', $root->path . '/public');
        $serial = 0;
        $last = null;

        try {
            $result = BenchmarkHarness::measure(static function () use (
                $extractor,
                $source,
                $manifest,
                &$serial,
                &$last,
            ): void {
                $id = str_pad(dechex(++$serial), 32, '0', STR_PAD_LEFT);
                $last = $extractor->step($source, $manifest, $id, 20);
            }, 10, 1);
            self::assertTrue($last?->complete);
            fwrite(STDERR, 'safe_archive_20 ' . json_encode($result, JSON_THROW_ON_ERROR) . "\n");
        } finally {
            $root->remove();
        }
    }
}
