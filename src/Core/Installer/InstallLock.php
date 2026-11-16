<?php

declare(strict_types=1);

namespace Nolviko\Core\Installer;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Application\InstallationState;
use RuntimeException;

/**
 * Writes `config/install.lock`, the last step of the installer (M02.15a). With the lock and `local.php`
 * present the site is Installed and every installer route answers 404 (InstallationGuard). Written atomically
 * (temporary file in the same directory, then rename), so a half-written lock never exists.
 */
final class InstallLock
{
    /**
     * @throws RuntimeException `installer_lock_failed`; nothing is left behind.
     */
    public static function write(string $configDirectory, ?DateTimeImmutable $at = null): void
    {
        $file = $configDirectory . '/' . InstallationState::LOCK_FILE;
        $stamp = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
        $content = 'installed ' . $stamp . "\n";
        $temporary = $configDirectory . '/.install-' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
            @unlink($temporary);

            throw new RuntimeException('installer_lock_failed');
        }

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException('installer_lock_failed');
        }
    }
}
