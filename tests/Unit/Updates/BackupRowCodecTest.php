<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Updates;

use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Core\Updates\BackupRowCodec;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupRowCodecTest extends TestCase
{
    public function testBinaryNullEmptyAndNumericTextSurvivePrivateStorage(): void
    {
        $root = new TemporaryDirectory();
        chmod($root->path, 0700);
        mkdir($root->path . '/public');

        try {
            $store = new BackupChunkStore($root->path, $root->path . '/public');
            $values = [null, '', "\0\xff\n", '9223372036854775807', '1.230000', 'emoji ' . "\u{1F600}"];
            $line = BackupRowCodec::encode($values);
            $id = str_repeat('f', 32);
            $digest = $store->put($id, $line);
            self::assertSame($values, BackupRowCodec::decode($store->read($id, $digest), count($values)));
        } finally {
            $root->remove();
        }
    }

    public function testOversizedRowFailsClosed(): void
    {
        $this->expectExceptionMessage('backup_row_budget');
        BackupRowCodec::encode([str_repeat('x', BackupRowCodec::MAX_ROW_BYTES + 1)]);
    }

    public function testNumericCoercionIsRejected(): void
    {
        $this->expectExceptionMessage('backup_row_type_invalid');
        BackupRowCodec::encode([123]);
    }

    #[DataProvider('invalidFrames')]
    public function testMalformedOrNoncanonicalFrameIsRejected(string $line): void
    {
        $this->expectException(RuntimeException::class);
        BackupRowCodec::decode($line, 1);
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidFrames(): array
    {
        return [
            ['{}'],
            ["[2,[null]]\n"],
            ["[1,[123]]\n"],
            ["[1,[\"YQ\"]]\n"],
            ["[1,[\"!!\"]]\n"],
            ["[1,[]]\n"],
            ["[1,[null,null]]\n"],
            ["[1, [null]]\n"],
            ['[1,[null]]'],
        ];
    }
}
