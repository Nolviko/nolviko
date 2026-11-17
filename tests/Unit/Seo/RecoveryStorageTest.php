<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Seo;

use Nolviko\Core\Updates\FrozenRecoveryRuntime;
use Nolviko\Core\Updates\RecoveryPreparation;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Recovery storage: outside the application, or its own storage/ with the deny-all .htaccess (2026-11-17). */
final class RecoveryStorageTest extends TestCase
{
    public function testExternalPrivateStoragePreparesACompleteBridgeWithoutCopyingSecrets(): void
    {
        $files = new TemporaryDirectory();
        $private = new TemporaryDirectory();
        try {
            foreach (
                ['config/bootstrap.php', 'config/runtime-policy.php', 'vendor/autoload.php',
                'src/Core/Http/RecoveryGateway.php', 'src/Core/Http/FrozenRecoveryEntry.php',
                'views/page.twig', 'translations/en/core.php'] as $path
            ) {
                $files->write($path, 'synthetic runtime fixture');
            }
            $files->write('public/.keep', '');
            $files->write('config/local.php', 'synthetic private configuration');
            $private->write('storage/.keep', '');
            chmod($private->path . '/storage', 0700);
            $job = str_repeat('a', 32);
            $report = (new RecoveryPreparation($files->path, $private->path . '/storage'))->prepare(
                $job,
                1,
                'https://forum.example.test',
                [],
                '<!doctype html><html><body></body></html>',
            );
            self::assertTrue($report['progress']['complete']);
            self::assertSame(7, $report['progress']['copied']);
            self::assertNotNull($report['grant']);
            self::assertFileExists($files->path . '/public/update-recovery.php');
            $runtime = $private->path . '/storage/recovery/frozen-' . $job . '/runtime';
            self::assertFileExists($runtime . '/vendor/autoload.php');
            self::assertFileDoesNotExist($runtime . '/config/local.php');
            self::assertDirectoryDoesNotExist($runtime . '/storage');
        } finally {
            $files->remove();
            $private->remove();
        }
    }

    public static function unsafeDestinations(): array
    {
        return [['storage'], ['src/private'], ['vendor/private'], ['views/private'],
            ['translations/private'], ['public/private']];
    }

    /**
     * Owner decisions 2026-11-17: storage/ inside the application (the ZIP uploaded as it is) at 755 prepares the
     * recovery bridge too, while its deny-all .htaccess stands; the snapshot never copies storage/.
     */
    public function testInternalStorageWithDenyFilePreparesTheBridgeAt755(): void
    {
        $files = new TemporaryDirectory();
        try {
            foreach (
                ['config/bootstrap.php', 'config/runtime-policy.php', 'vendor/autoload.php',
                'src/Core/Http/RecoveryGateway.php', 'src/Core/Http/FrozenRecoveryEntry.php',
                'views/page.twig', 'translations/en/core.php'] as $path
            ) {
                $files->write($path, 'synthetic runtime fixture');
            }
            $files->write('public/.keep', '');
            $files->write('storage/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n");
            chmod($files->path . '/storage', 0755);
            $job = str_repeat('d', 32);
            $report = (new RecoveryPreparation($files->path, $files->path . '/storage'))->prepare(
                $job,
                1,
                'https://forum.example.test',
                [],
                '<!doctype html><html><body></body></html>',
            );
            self::assertTrue($report['progress']['complete']);
            self::assertSame(7, $report['progress']['copied'], 'storage/ is not part of the snapshot');
            self::assertNotNull($report['grant']);
            self::assertFileExists($files->path . '/public/update-recovery.php');
        } finally {
            $files->remove();
        }
    }

    #[DataProvider('unsafeDestinations')]
    public function testInternalStorageIsRejectedBeforeSnapshotWork(string $destination): void
    {
        $files = new TemporaryDirectory();
        try {
            $files->write('public/.keep', '');
            $files->write($destination . '/.keep', '');
            chmod($files->path . '/' . $destination, 0700);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('recovery_storage_not_private');
            (new RecoveryPreparation($files->path, $files->path . '/' . $destination))->prepare(
                str_repeat('b', 32),
                1,
                'https://forum.example.test',
                [],
                '',
            );
        } finally {
            $files->remove();
        }
    }

    /** Owner decision 2026-11-17: storage/recovery inside the application is accepted; code folders never. */
    public function testFrozenControlAcceptsStorageButRejectsCodeFolders(): void
    {
        $files = new TemporaryDirectory();
        try {
            $files->write('storage/recovery/.keep', '');
            $files->write('src/recovery/.keep', '');
            chmod($files->path . '/storage/recovery', 0700);
            chmod($files->path . '/src/recovery', 0700);
            try {
                (new FrozenRecoveryRuntime($files->path, $files->path . '/storage/recovery'))
                    ->prepare(str_repeat('c', 32));
                self::fail('a fixture without vendor/ cannot be snapshotted');
            } catch (RuntimeException $failure) {
                self::assertSame('recovery_snapshot_source_invalid', $failure->getMessage(), 'configuration passed');
            }
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('recovery_snapshot_configuration_invalid');
            (new FrozenRecoveryRuntime($files->path, $files->path . '/src/recovery'))
                ->prepare(str_repeat('c', 32));
        } finally {
            $files->remove();
        }
    }
}
