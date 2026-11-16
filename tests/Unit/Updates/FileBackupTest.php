<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Updates;

use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Core\Updates\FileBackup;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileBackupTest extends TestCase
{
    #[DataProvider('unsafePaths')]
    public function testUnsafeInventoryIsRejected(array $paths): void
    {
        $root = new TemporaryDirectory();
        mkdir($root->path . '/public');
        mkdir($root->path . '/private', 0700);

        try {
            $store = new BackupChunkStore($root->path . '/private', $root->path . '/public');
            $this->expectException(RuntimeException::class);
            FileBackup::export($store, $root->path, $paths, static function (): void {
            });
        } finally {
            $root->remove();
        }
    }

    /**
     * @return list<array{list<string>}>
     */
    public static function unsafePaths(): array
    {
        return [
            [['../outside']],
            [['storage/cache/data']],
            [['_backup_2026/data']],
            [['storage/updates/a']],
            [['a', 'A']],
            [['a', 'a/b']],
            [['a/b', 'a']],
        ];
    }

    public function testSmallFilesShareFewPackedChunksAndRestoreExactly(): void
    {
        $root = new TemporaryDirectory();
        mkdir($root->path . '/public');
        mkdir($root->path . '/private', 0700);
        mkdir($root->path . '/output', 0700);
        $paths = [];

        for ($i = 0; $i < 300; $i++) {
            $root->write('src/f' . $i . '.php', str_repeat('x' . $i, 100 + $i));
            $paths[] = 'src/f' . $i . '.php';
        }

        $root->write('empty.txt', '');
        $paths[] = 'empty.txt';

        try {
            $store = new BackupChunkStore($root->path . '/private', $root->path . '/public');
            $handle = FileBackup::export($store, $root->path, $paths, static function (): void {
            });

            // 300 files, about 0.3 MiB: one pack plus the manifest instead of 301 chunk files.
            self::assertLessThanOrEqual(3, count(glob($root->path . '/private/*.chunk') ?: []));
            FileBackup::restore($store, $handle, $root->path . '/output');

            foreach ($paths as $path) {
                self::assertSame(
                    file_get_contents($root->path . '/' . $path),
                    file_get_contents($root->path . '/output/' . $path),
                    $path,
                );
            }
        } finally {
            $root->remove();
        }
    }

    public function testOversizedFileIsRefusedBeforeReading(): void
    {
        $root = new TemporaryDirectory();
        mkdir($root->path . '/public');
        mkdir($root->path . '/private', 0700);
        $stream = fopen($root->path . '/large', 'w+b');
        ftruncate($stream, 33554433);
        fclose($stream);

        try {
            $store = new BackupChunkStore($root->path . '/private', $root->path . '/public');
            $this->expectExceptionMessage('backup_file_budget');
            FileBackup::export($store, $root->path, ['large'], static function (): void {
            });
        } finally {
            $root->remove();
        }
    }

    public function testUnavailableStorageCannotPublishAnInventory(): void
    {
        $root = new TemporaryDirectory();
        mkdir($root->path . '/public');
        mkdir($root->path . '/private', 0700);
        $root->write('source', 'fixture');

        try {
            $store = new BackupChunkStore($root->path . '/private', $root->path . '/public');
            rmdir($root->path . '/private');
            $root->write('private', 'blocked');
            $this->expectExceptionMessage('backup_chunk_identity_invalid');
            FileBackup::export($store, $root->path, ['source'], static function (): void {
            });
        } finally {
            $root->remove();
        }
    }

    public function testChangeToAnEarlierFilePreventsPublication(): void
    {
        $root = new TemporaryDirectory();
        mkdir($root->path . '/public');
        mkdir($root->path . '/private', 0700);
        $root->write('a', 'original');
        $root->write('b', 'second');
        $calls = 0;

        try {
            $store = new BackupChunkStore($root->path . '/private', $root->path . '/public');
            $this->expectExceptionMessage('backup_file_changed');
            FileBackup::export($store, $root->path, ['a', 'b'], static function () use ($root, &$calls): void {
                if (++$calls === 2) {
                    $root->write('a', 'changed');
                }
            });
        } finally {
            $root->remove();
        }
    }

    public function testSymlinkSourceIsRejected(): void
    {
        $root = new TemporaryDirectory();
        mkdir($root->path . '/public');
        mkdir($root->path . '/private', 0700);
        $root->write('source', 'fixture');
        symlink($root->path . '/source', $root->path . '/link');

        try {
            $store = new BackupChunkStore($root->path . '/private', $root->path . '/public');
            $this->expectExceptionMessage('backup_file_link_forbidden');
            FileBackup::export($store, $root->path, ['link'], static function (): void {
            });
        } finally {
            $root->remove();
        }
    }

    public function testCorruptionLeavesRestoreDestinationEmpty(): void
    {
        $root = new TemporaryDirectory();
        mkdir($root->path . '/public');
        mkdir($root->path . '/private', 0700);
        mkdir($root->path . '/output', 0700);
        $root->write('source', 'fixture');

        try {
            $store = new BackupChunkStore($root->path . '/private', $root->path . '/public');
            $handle = FileBackup::export($store, $root->path, ['source'], static function (): void {
            });
            $manifest = json_decode($store->read($handle['id'], $handle['digest']), true, 32, JSON_THROW_ON_ERROR);
            $chunk = $manifest['files'][0]['chunks'][0];
            file_put_contents($root->path . '/private/' . $chunk['id'] . '.chunk', 'corrupt');

            try {
                FileBackup::restore($store, $handle, $root->path . '/output');
                self::fail('Expected corrupt chunk rejection');
            } catch (RuntimeException $failure) {
                self::assertSame('backup_chunk_integrity_failed', $failure->getMessage());
            }

            self::assertSame(['.', '..'], scandir($root->path . '/output'));
        } finally {
            $root->remove();
        }
    }
}
