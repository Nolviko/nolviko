<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;

/** Publishes only a fixed bootstrap. No caller-supplied PHP, endpoint path or update command is accepted. */
final class RecoveryBridgePublisher
{
    /** @param list<string> $proxies */
    public static function publish(
        string $site,
        string $storage,
        string $private,
        string $job,
        string $origin,
        array $proxies,
        string $html,
    ): void {
        $url = parse_url($origin);

        if (
            $url === false || ($url['scheme'] ?? null) !== 'https' || !isset($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || (isset($url['path']) && $url['path'] !== '') || strlen($html) > 65536
            || strlen($origin) > 512
            || preg_match('/^[a-f0-9]{32}$/D', $job) !== 1 || count($proxies) > 100
        ) {
            throw new RuntimeException('recovery_bridge_configuration_invalid');
        }

        foreach ($proxies as $proxy) {
            if (!is_string($proxy) || strlen($proxy) > 100) {
                throw new RuntimeException('recovery_bridge_configuration_invalid');
            }
        }

        $public = $site . '/public';

        if (stat($public)['dev'] !== stat($private)['dev']) {
            throw new RuntimeException('recovery_bridge_filesystem_mismatch');
        }

        $lockPath = $private . '/entry.lock';
        self::regular($lockPath);
        $lock = fopen($lockPath, 'c+b');

        if ($lock === false) {
            throw new RuntimeException('recovery_bridge_unwritable');
        }

        try {
            if (!chmod($lockPath, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('recovery_bridge_busy');
            }

            $directory = $private . '/frozen-' . $job;
            $contextPath = $directory . '/entry.json';
            self::regular($contextPath);

            if (is_file($contextPath)) {
                $prior = json_decode((string) file_get_contents($contextPath), true, 8, JSON_THROW_ON_ERROR);

                if (($prior['origin'] ?? null) !== $origin) {
                    throw new RuntimeException('recovery_bridge_origin_changed');
                }
            }

            self::write($directory . '/recovery.html', $html);
            self::write($contextPath, json_encode([
                'origin' => $origin,
                'proxies' => $proxies,
                'shell_hash' => hash('sha256', $html),
            ], JSON_THROW_ON_ERROR));
            $template = file_get_contents(__DIR__ . '/recovery-entry-template.php.txt');

            if ($template === false) {
                throw new RuntimeException('recovery_bridge_template_missing');
            }

            $code = strtr($template, [
                '__SITE_LITERAL__' => var_export($site, true),
                '__STORAGE_LITERAL__' => var_export($storage, true),
                '__PRIVATE_LITERAL__' => var_export($private, true),
            ]);
            $target = $public . '/update-recovery.php';
            self::regular($target);

            if (is_file($target)) {
                if (hash_file('sha256', $target) !== hash('sha256', $code)) {
                    throw new RuntimeException('recovery_bridge_existing_entry_changed');
                }
            } else {
                $temporary = $private . '/entry-' . bin2hex(random_bytes(8)) . '.php';
                self::write($temporary, $code);

                if (!chmod($temporary, 0644) || !rename($temporary, $target)) {
                    throw new RuntimeException('recovery_bridge_unwritable');
                }
            }

            self::write($private . '/current.json', json_encode(['job' => $job], JSON_THROW_ON_ERROR));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function write(string $path, string $bytes): void
    {
        self::regular($path);
        $temporary = $path . '.pending';
        self::regular($temporary);
        $stream = fopen($temporary, 'w+b');

        if ($stream === false) {
            throw new RuntimeException('recovery_bridge_unwritable');
        }

        try {
            if (!chmod($temporary, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fsync($stream)) {
                throw new RuntimeException('recovery_bridge_unwritable');
            }
        } finally {
            fclose($stream);
        }

        if (!rename($temporary, $path)) {
            throw new RuntimeException('recovery_bridge_unwritable');
        }
    }

    private static function regular(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('recovery_bridge_path_invalid');
        }
    }
}
