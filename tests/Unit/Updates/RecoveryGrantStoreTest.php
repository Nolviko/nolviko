<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Updates;

use Nolviko\Core\Updates\RecoveryGrantStore;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** 2026-11-17: grants renew on every accepted command (one-click updates), and stay bound to sequence and code. */
final class RecoveryGrantStoreTest extends TestCase
{
    public function testAcceptedCommandsRenewTheWindowAndWrongCodesAreRefused(): void
    {
        $files = new TemporaryDirectory();
        try {
            $files->write('public/.keep', '');
            $files->write('private/grants/.keep', '');
            chmod($files->path . '/private/grants', 0700);
            $store = new RecoveryGrantStore($files->path . '/private/grants', $files->path . '/public');
            $grant = $store->issue(str_repeat('e', 32), 1, 60);
            $file = $files->path . '/private/grants/' . $grant['id'] . '.json';
            $state = json_decode((string) file_get_contents($file), true);
            $state['expires'] = time() + 5;
            file_put_contents($file, json_encode($state));

            $used = $store->useGrant(
                $grant['id'],
                $grant['code'],
                $grant['nonce'],
                0,
                static fn(int $actor): bool => $actor === 1,
                static fn(string $job, int $actor): array => ['stage' => 'staged'],
            );
            self::assertSame(1, $used['sequence']);
            $renewed = json_decode((string) file_get_contents($file), true)['expires'];
            self::assertGreaterThanOrEqual(time() + 55, $renewed, 'the window slid forward');

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('recovery_grant_refused');
            $store->useGrant(
                $grant['id'],
                str_repeat('x', 43),
                $grant['nonce'],
                1,
                static fn(int $actor): bool => true,
                static fn(string $job, int $actor): array => [],
            );
        } finally {
            $files->remove();
        }
    }
}
