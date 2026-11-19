<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Importers;

use Nolviko\Modules\Importers\Infrastructure\ImportFileStore;
use Nolviko\Tests\Support\FileAsset;
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
            $asset = new FileAsset($source);
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
                new FileAsset($source)
            );
        } finally {
            $root->remove();
        }
    }
}
