<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Updates;

use Nolviko\Core\Updates\ArchivePath;
use Nolviko\Core\Updates\ReleaseArchivePlan;
use Nolviko\Core\Updates\SafeReleaseArchive;
use Nolviko\Core\Updates\SignedReleaseManifest;
use Nolviko\Tests\Support\ReleaseSigningFixture;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Signed ZIP identity, portable path policy, bounds, private streaming writes and restartable checkpoints.
 */
final class SafeReleaseArchiveTest extends TestCase
{
    private const JOB = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private TemporaryDirectory $root;

    private SafeReleaseArchive $extractor;

    private int $serial = 0;

    /** @var list<string> */
    private array $links = [];

    protected function setUp(): void
    {
        $this->root = new TemporaryDirectory();
        $this->root->write('private/.keep', '');
        $this->root->write('public/.keep', '');
        $this->extractor = new SafeReleaseArchive($this->root->path . '/private', $this->root->path . '/public');
    }

    protected function tearDown(): void
    {
        foreach ($this->links as $path) {
            if (is_link($path)) {
                unlink($path);
            }
        }

        $this->root->remove();
    }

    public function testBoundedStepsResumeFromThePrivateCopyWithoutTheOriginalSource(): void
    {
        $source = $this->archive(['src/a.php' => 'alpha', 'public/app.css' => 'beta']);
        $manifest = $this->manifest($source);
        $first = $this->extractor->step($source, $manifest, self::JOB, 1);
        self::assertFalse($first->complete);
        self::assertSame(1, $first->nextIndex);
        self::assertSame('alpha', file_get_contents($first->directory . '/src/a.php'));
        self::assertFileDoesNotExist($first->directory . '/public/app.css');
        unlink($source);
        $restarted = new SafeReleaseArchive($this->root->path . '/private', $this->root->path . '/public');
        $last = $restarted->step($source, $manifest, self::JOB, 1);
        self::assertTrue($last->complete);
        self::assertSame(2, $last->nextIndex);
        self::assertSame('beta', file_get_contents($last->directory . '/public/app.css'));
        self::assertSame(0600, fileperms($last->directory . '/public/app.css') & 0777);
        self::assertSame(0700, fileperms($last->directory) & 0777);
        self::assertSame(2, $restarted->step($source, $manifest, self::JOB)->nextIndex);
        self::assertFileDoesNotExist($this->root->path . '/public/app.css');
    }

    public function testCrashBeforeCursorCommitReplaysPrivateFilesSafely(): void
    {
        $source = $this->archive(['a.txt' => 'alpha', 'b.txt' => 'beta']);
        $manifest = $this->manifest($source);
        $first = $this->extractor->step($source, $manifest, self::JOB, 1);
        $job = dirname($first->directory);
        file_put_contents($job . '/progress.json', json_encode([
            'manifest_hash' => $manifest->manifestHash,
            'next_index' => 0,
        ], JSON_THROW_ON_ERROR));
        file_put_contents($job . '/current.part', 'interrupted bytes');
        file_put_contents($first->directory . '/a.txt', 'interrupted replacement');
        $again = $this->extractor->step($source, $manifest, self::JOB, 1);
        self::assertSame(1, $again->nextIndex);
        self::assertSame('alpha', file_get_contents($again->directory . '/a.txt'));
        self::assertFileDoesNotExist($job . '/current.part');
        self::assertTrue($this->extractor->step($source, $manifest, self::JOB)->complete);
    }

    public function testCorruptProgressFailsClosed(): void
    {
        $source = $this->archive(['a.txt' => 'alpha', 'b.txt' => 'beta']);
        $manifest = $this->manifest($source);
        $first = $this->extractor->step($source, $manifest, self::JOB, 1);
        file_put_contents(dirname($first->directory) . '/progress.json', '{');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_extraction_state_invalid');
        $this->extractor->step($source, $manifest, self::JOB);
    }

    public function testExistingJobCannotBeReboundToAnotherSignedManifest(): void
    {
        $source = $this->archive(['a.txt' => 'alpha', 'b.txt' => 'beta']);
        $manifest = $this->manifest($source);
        $this->extractor->step($source, $manifest, self::JOB, 1);
        $changed = $this->manifest($source, ['sequence' => 3]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_extraction_state_invalid');
        $this->extractor->step($source, $changed, self::JOB);
    }

    public function testArchiveHashMismatchIsRejectedBeforePayloadWrites(): void
    {
        $source = $this->archive(['a.txt' => 'alpha']);
        $manifest = $this->manifest($source, ['archive_sha256' => str_repeat('b', 64)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_archive_identity_mismatch');
        $this->extractor->step($source, $manifest, self::JOB);
    }

    public function testSignedEntryCountMustMatchTheArchive(): void
    {
        $source = $this->archive(['a.txt' => 'alpha']);
        $manifest = $this->manifest($source, ['file_count' => 2]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_archive_manifest_mismatch');
        $this->extractor->step($source, $manifest, self::JOB);
    }

    public function testSignedUnpackedSizeMustMatchTheArchive(): void
    {
        $source = $this->archive(['a.txt' => 'alpha']);
        $manifest = $this->manifest($source, ['unpacked_bytes' => 4]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_archive_manifest_mismatch');
        $this->extractor->step($source, $manifest, self::JOB);
    }

    #[DataProvider('unsafeNames')]
    public function testUnsafeNamesAreRejectedBeforeAnyPayloadIsWritten(string $name): void
    {
        $source = $this->archive([$name => 'payload']);
        $manifest = $this->manifest($source);

        try {
            $this->extractor->step($source, $manifest, self::JOB);
            self::fail('unsafe archive name was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('update_archive_path_invalid', $error->getMessage(), $name);
        }

        self::assertDirectoryDoesNotExist($this->root->path . '/private/updates/' . self::JOB . '/stage');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeNames(): array
    {
        return [
            'parent traversal' => ['../escape.php'],
            'embedded traversal' => ['src/../escape.php'],
            'dot component' => ['src/./file.php'],
            'absolute' => ['/etc/file.php'],
            'drive' => ['C:/file.php'],
            'backslash' => ['src\\file.php'],
            'alternate stream' => ['file.php:stream'],
            'encoded traversal' => ['%2e%2e/file.php'],
            'empty component' => ['src//file.php'],
            'trailing dot' => ['src/file.'],
            'trailing space' => ['src/file '],
            'reserved device' => ['NUL.txt'],
            'reserved nested device' => ['src/com1.php'],
            'control character' => ["src/evil\n.php"],
            'unicode ambiguity' => ["src/\u{0131}.php"],
            'path budget' => [str_repeat('a', ArchivePath::MAX_BYTES + 1)],
            'depth budget' => [str_repeat('a/', ArchivePath::MAX_DEPTH) . 'file.php'],
        ];
    }

    /**
     * @param array<string, string> $entries
     */
    #[DataProvider('collidingEntries')]
    public function testAliasesAndFileDirectoryCollisionsAreRejected(array $entries): void
    {
        $source = $this->archive($entries);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_archive_path_collision');
        $this->extractor->step($source, $this->manifest($source), self::JOB);
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function collidingEntries(): array
    {
        return [
            'case collision' => [['A.php' => 'a', 'a.php' => 'b']],
            'file parent' => [['a' => 'a', 'a/b.txt' => 'b']],
            'file parent reversed' => [['a/b.txt' => 'b', 'a' => 'a']],
            'implicit directory case' => [['Src/a.php' => 'a', 'src/b.php' => 'b']],
            'explicit directory case' => [['SRC/' => '', 'src/b.php' => 'b']],
        ];
    }

    public function testSymbolicLinkEntriesAreRejected(): void
    {
        $source = $this->archive(['link' => 'target']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($source));
        self::assertTrue($zip->setExternalAttributesName('link', ZipArchive::OPSYS_UNIX, 0120777 << 16));
        $zip->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_archive_entry_unsupported');
        $this->extractor->step($source, $this->manifest($source), self::JOB);
    }

    public function testEncryptedEntriesAreRejectedWithoutTryingAPassword(): void
    {
        $source = $this->archive(['a.txt' => 'alpha']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($source));
        self::assertTrue($zip->setEncryptionName('a.txt', ZipArchive::EM_TRAD_PKWARE, 'test-only'));
        $zip->close();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_archive_entry_unsupported');
        $this->extractor->step($source, $this->manifest($source), self::JOB);
    }

    public function testCompressionBombRatioIsRejected(): void
    {
        $source = $this->archive(['zeros.txt' => str_repeat('a', 2 * 1048576)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_archive_size_budget');
        $this->extractor->step($source, $this->manifest($source), self::JOB);
    }

    public function testLargeIndividualFileIsRejectedEvenWithSafeCompression(): void
    {
        $source = $this->archive(['large.bin' => random_bytes(ReleaseArchivePlan::MAX_FILE_BYTES + 1)]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_archive_size_budget');
        $this->extractor->step($source, $this->manifest($source), self::JOB);
    }

    public function testTamperedStoredContentFailsCrcAndIsNotPromoted(): void
    {
        $source = $this->archive(['a.txt' => 'original-content']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($source));
        self::assertTrue($zip->setCompressionName('a.txt', ZipArchive::CM_STORE));
        $zip->close();
        $bytes = (string) file_get_contents($source);
        self::assertStringContainsString('original-content', $bytes);
        file_put_contents($source, str_replace('original-content', 'modified-content', $bytes));
        $this->expectException(RuntimeException::class);
        $this->extractor->step($source, $this->manifest($source), self::JOB);
    }

    public function testExplicitDirectoriesAndEmptyFilesRemainRegularPrivateFiles(): void
    {
        $source = $this->archive(['src/' => '', 'src/empty.txt' => '', 'src/a.txt' => 'alpha']);
        $result = $this->extractor->step($source, $this->manifest($source), self::JOB);
        self::assertTrue($result->complete);
        self::assertSame('', file_get_contents($result->directory . '/src/empty.txt'));
        self::assertFalse(is_link($result->directory . '/src/empty.txt'));
    }

    public function testPrivateArchiveTamperingIsDetectedOnResume(): void
    {
        $source = $this->archive(['a.txt' => 'alpha', 'b.txt' => 'beta']);
        $manifest = $this->manifest($source);
        $first = $this->extractor->step($source, $manifest, self::JOB, 1);
        file_put_contents(dirname($first->directory) . '/release.zip', 'changed archive');
        clearstatcache();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_archive_identity_mismatch');
        $this->extractor->step($source, $manifest, self::JOB);
    }

    public function testMissingProgressCannotSilentlyAdoptExistingStagedFiles(): void
    {
        $source = $this->archive(['a.txt' => 'alpha', 'b.txt' => 'beta']);
        $manifest = $this->manifest($source);
        $first = $this->extractor->step($source, $manifest, self::JOB, 1);
        unlink(dirname($first->directory) . '/progress.json');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_extraction_state_missing');
        $this->extractor->step($source, $manifest, self::JOB);
    }

    public function testCompletedCursorCannotConcealAMissingStagedFile(): void
    {
        $source = $this->archive(['a.txt' => 'alpha']);
        $manifest = $this->manifest($source);
        $complete = $this->extractor->step($source, $manifest, self::JOB);
        self::assertTrue($complete->complete);
        unlink($complete->directory . '/a.txt');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_staged_content_missing');
        $this->extractor->step($source, $manifest, self::JOB);
    }

    public function testInvalidJobCannotEscapePrivateStorage(): void
    {
        $source = $this->archive(['a.txt' => 'alpha']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_extraction_request_invalid');
        $this->extractor->step($source, $this->manifest($source), '../escape');
    }

    public function testDirectoryEntryBombIsRejectedBeforePlanningThePayload(): void
    {
        $path = $this->root->path . '/directory-bomb.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL));

        for ($index = 0; $index < ReleaseArchivePlan::MAX_ENTRIES; ++$index) {
            if (!$zip->addEmptyDir('d' . $index)) {
                self::fail('directory fixture creation failed');
            }
        }

        self::assertTrue($zip->addFromString('file.txt', 'x'));
        self::assertTrue($zip->close());
        $manifest = ReleaseSigningFixture::verify(ReleaseSigningFixture::body([
            'archive_bytes' => filesize($path),
            'archive_sha256' => hash_file('sha256', $path),
            'file_count' => 1,
            'unpacked_bytes' => 1,
        ]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_archive_entry_budget');
        $this->extractor->step($path, $manifest, self::JOB);
    }

    public function testPublicStagingIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_storage_public');
        new SafeReleaseArchive($this->root->path . '/public', $this->root->path . '/public');
    }

    public function testStagingSymlinkCannotRedirectAWrittenFile(): void
    {
        $source = $this->archive(['a.txt' => 'alpha', 'src/b.txt' => 'beta']);
        $manifest = $this->manifest($source);
        $first = $this->extractor->step($source, $manifest, self::JOB, 1);
        $outside = dirname($this->root->write('outside/.keep', ''));
        $link = $first->directory . '/src';
        self::assertTrue(symlink($outside, $link));
        $this->links[] = $link;

        try {
            $this->extractor->step($source, $manifest, self::JOB);
            self::fail('staging symlink was followed');
        } catch (RuntimeException $error) {
            self::assertSame('update_staging_path_unsafe', $error->getMessage());
        }

        self::assertFileDoesNotExist($outside . '/b.txt');
    }

    public function testConcurrentStepCannotAcquireTheSameFilesystemLock(): void
    {
        $source = $this->archive(['a.txt' => 'alpha', 'b.txt' => 'beta']);
        $manifest = $this->manifest($source);
        $first = $this->extractor->step($source, $manifest, self::JOB, 1);
        $lock = fopen(dirname($first->directory) . '/lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('update_extraction_busy');

        try {
            $this->extractor->step($source, $manifest, self::JOB);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string, string> $entries
     */
    private function archive(array $entries): string
    {
        $path = $this->root->path . '/source-' . (++$this->serial) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL));

        foreach ($entries as $name => $content) {
            self::assertTrue(
                str_ends_with($name, '/') ? $zip->addEmptyDir($name) : $zip->addFromString($name, $content),
                $name,
            );
        }

        self::assertTrue($zip->close());

        return $path;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function manifest(string $source, array $changes = []): SignedReleaseManifest
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($source));
        $bytes = 0;
        $files = 0;

        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $stat = $zip->statIndex($index);
            self::assertIsArray($stat);
            $bytes += $stat['size'];
            $files += str_ends_with($stat['name'], '/') ? 0 : 1;
        }

        $zip->close();
        $body = ReleaseSigningFixture::body(array_replace([
            'archive_bytes' => filesize($source),
            'archive_sha256' => hash_file('sha256', $source),
            'file_count' => $files,
            'unpacked_bytes' => $bytes,
        ], $changes));

        return ReleaseSigningFixture::verify($body);
    }
}
