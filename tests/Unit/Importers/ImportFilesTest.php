<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Importers;

use Nolviko\Modules\Importers\Infrastructure\ImportFileStore;
use Nolviko\Modules\Importers\Infrastructure\LocalImportAsset;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImportFilesTest extends TestCase
{
    public function testBinaryOriginalRetryAndChangedSourceAreNeverSilentlyOverwritten(): void
    {
        $root = new TemporaryDirectory();
        $bytes = "\0\xfforiginal\r\n";
        $source = $root->write('source/file.dat', $bytes);
        $root->write('storage/.keep', '');
        $root->write('public/.keep', '');
        chmod($root->path . '/storage', 0700);
        try {
            $asset = new LocalImportAsset($root->path . '/source', 'file.dat', strlen($bytes));
            $store = new ImportFileStore($root->path . '/storage', $root->path . '/public');
            $first = $store->retain(str_repeat('a', 32), 1, $asset);
            self::assertSame(hash('sha256', $bytes), $first['sha256']);
            self::assertSame($bytes, file_get_contents($store->path($first['key'])));
            self::assertSame($first, $store->retain(str_repeat('a', 32), 1, $asset));
            file_put_contents($source, str_repeat('x', strlen($bytes)));
            $this->expectException(RuntimeException::class);
            $store->retain(
                str_repeat('a', 32),
                1,
                new LocalImportAsset($root->path . '/source', 'file.dat', strlen($bytes))
            );
        } finally {
            $root->remove();
        }
    }

    public function testTraversalCannotReadSiblingSourceFile(): void
    {
        $root = new TemporaryDirectory();
        $root->write('source/.keep', '');
        $root->write('secret', 'secret');
        try {
            $this->expectException(RuntimeException::class);
            new LocalImportAsset($root->path . '/source', '../secret', 6);
        } finally {
            $root->remove();
        }
    }

    public function testOversizeOriginalIsRejectedBeforeCopy(): void
    {
        $root = new TemporaryDirectory();
        $root->write('source/file.dat', 'x');
        try {
            $this->expectException(RuntimeException::class);
            new LocalImportAsset($root->path . '/source', 'file.dat', 33554433);
        } finally {
            $root->remove();
        }
    }
}
