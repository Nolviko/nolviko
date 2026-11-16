<?php

declare(strict_types=1);

namespace Nolviko\Core\Config;

use RuntimeException;

/**
 * Writes `config/local.php` for the installer: atomically (temporary file in the same directory, then
 * rename), readable by the owner only (0600 where the host allows), and never inside the public web root.
 */
final class ConfigWriter
{
    private const FILE_MODE = 0600;

    /**
     * @param array<string, array<string, string|int|bool|list<string>|null>> $settings The local.php shape.
     * @param string $publicDirectory The application's `public/` (document root); the file must be outside it.
     *
     * @throws RuntimeException `config_write_failed` or `config_directory_public`; nothing is left behind.
     */
    public static function write(string $file, array $settings, string $publicDirectory): void
    {
        $directory = dirname($file);
        $real = realpath($directory);
        $public = realpath($publicDirectory);

        if ($real === false) {
            throw new RuntimeException('config_write_failed');
        }

        if ($public !== false && str_starts_with($real . DIRECTORY_SEPARATOR, $public . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('config_directory_public');
        }

        $source = '<?php' . "\n\n" . 'declare(strict_types=1);' . "\n\n"
            . '// Written by the installer. Keep this file private; it contains the database password.' . "\n"
            . 'return ' . var_export($settings, true) . ';' . "\n";
        $temporary = $directory . '/.local-' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temporary, $source, LOCK_EX) !== strlen($source)) {
            @unlink($temporary);

            throw new RuntimeException('config_write_failed');
        }

        @chmod($temporary, self::FILE_MODE);

        if (!@rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException('config_write_failed');
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    /**
     * A new application secret: 32 random bytes as 64 hexadecimal characters.
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(ConfigSchema::APP_SECRET_MIN_BYTES));
    }
}
