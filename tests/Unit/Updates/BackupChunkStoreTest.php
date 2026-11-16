<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Updates;

use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BackupChunkStoreTest extends TestCase
{
    public function testBinaryRoundTripAndCorruptionRejection(): void
    {
        $root = new TemporaryDirectory();
        chmod($root->path, 0700);
        mkdir($root->path . '/public');
        mkdir($root->path . '/private', 0700);

        try {
            $store = new BackupChunkStore($root->path . '/private', $root->path . '/public');
            $id = str_repeat('a', 32);
            $bytes = "binary\0data";
            $digest = $store->put($id, $bytes);
            self::assertSame($bytes, $store->read($id, $digest));
            file_put_contents($root->path . '/private/' . $id . '.chunk', 'changed');
            $this->expectExceptionMessage('backup_chunk_integrity_failed');
            $store->read($id, $digest);
        } finally {
            $root->remove();
        }
    }

    public function testExistingChunkCannotBeOverwritten(): void
    {
        $root = new TemporaryDirectory();
        chmod($root->path, 0700);
        mkdir($root->path . '/public');

        try {
            $store = new BackupChunkStore($root->path, $root->path . '/public');
            $id = str_repeat('b', 32);
            $digest = $store->put($id, 'original');

            try {
                $store->put($id, 'replacement');
                self::fail('Expected immutable chunk');
            } catch (RuntimeException $failure) {
                self::assertSame('backup_chunk_exists_or_unwritable', $failure->getMessage());
            }

            self::assertSame('original', $store->read($id, $digest));
        } finally {
            $root->remove();
        }
    }

    public function testOversizedChunkIsRejectedBeforeWriting(): void
    {
        $root = new TemporaryDirectory();
        chmod($root->path, 0700);
        mkdir($root->path . '/public');

        try {
            $store = new BackupChunkStore($root->path, $root->path . '/public');
            $this->expectExceptionMessage('backup_chunk_budget');
            $store->put(str_repeat('d', 32), str_repeat('x', BackupChunkStore::MAX_CHUNK_BYTES + 1));
        } finally {
            $root->remove();
        }
    }

    public function testPathEscapeIsRejected(): void
    {
        $root = new TemporaryDirectory();
        chmod($root->path, 0700);
        mkdir($root->path . '/public');

        try {
            $store = new BackupChunkStore($root->path, $root->path . '/public');
            $this->expectExceptionMessage('backup_chunk_identity_invalid');
            $store->put('../outside', 'data');
        } finally {
            $root->remove();
        }
    }

    public function testSymlinkReadIsRejected(): void
    {
        $root = new TemporaryDirectory();
        chmod($root->path, 0700);
        mkdir($root->path . '/public');

        try {
            $store = new BackupChunkStore($root->path, $root->path . '/public');
            $id = str_repeat('e', 32);
            $source = $root->write('source', 'secret');
            self::assertTrue(symlink($source, $root->path . '/' . $id . '.chunk'));
            $this->expectExceptionMessage('backup_chunk_invalid');
            $store->read($id, hash('sha256', 'secret'));
        } finally {
            $root->remove();
        }
    }

    public function testPublicStorageIsRejected(): void
    {
        $root = new TemporaryDirectory();
        chmod($root->path, 0700);

        try {
            $this->expectExceptionMessage('backup_storage_public');
            new BackupChunkStore($root->path, $root->path);
        } finally {
            $root->remove();
        }
    }
}
