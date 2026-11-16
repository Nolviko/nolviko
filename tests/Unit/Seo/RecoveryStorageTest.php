<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Seo;

use Nolviko\Core\Updates\FrozenRecoveryRuntime;
use Nolviko\Core\Updates\RecoveryPreparation;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** The updater requires external private storage; manual updates remain available on default installations. */
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

    public function testFrozenControlStillRejectsDefaultInternalStorage(): void
    {
        $files = new TemporaryDirectory();
        try {
            $files->write('storage/recovery/.keep', '');
            chmod($files->path . '/storage/recovery', 0700);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('recovery_snapshot_configuration_invalid');
            (new FrozenRecoveryRuntime($files->path, $files->path . '/storage/recovery'))
                ->prepare(str_repeat('c', 32));
        } finally {
            $files->remove();
        }
    }
}
