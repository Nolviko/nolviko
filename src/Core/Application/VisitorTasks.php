<?php

declare(strict_types=1);

namespace Nolviko\Core\Application;

use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Database\ConnectionFactory;
use Throwable;

/**
 * Post-response composition root. No session, shell, network loopback, cron or privileged browser endpoint.
 *
 * 2026-11-17 (owner: sites without cron must still build sitemaps and send mail): at most one tick every
 * INTERVAL_SECONDS across all visitors; a tick runs a time-boxed batch. When PHP has already handed the page to the
 * visitor (PHP-FPM, LiteSpeed) the batch is larger; otherwise it stays small so nobody waits for it.
 */
final class VisitorTasks
{
    public const INTERVAL_SECONDS = 15;

    /** Time of the last automatic tick, or null when visits have not run tasks yet. */
    public static function lastRun(string $storage): ?int
    {
        $file = $storage . '/tasks/tick';
        $last = is_file($file) && !is_link($file) ? @file_get_contents($file, false, null, 0, 32) : false;

        return is_string($last) && ctype_digit(trim($last)) ? (int) trim($last) : null;
    }

    public static function tick(string $root): void
    {
        $lock = null;

        try {
            if (
                PHP_SAPI === 'cli' || InstallationState::detect($root . '/config') !== InstallationState::Installed
                || file_exists($root . '/config/maintenance.lock') || is_link($root . '/config/maintenance.lock')
                || microtime(true) - (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)) > 15
            ) {
                return;
            }

            $environment = getenv();
            $environment = is_array($environment) ? $environment : [];
            $storage = realpath(($environment['FORUM_STORAGE_DIR'] ?? '') ?: $root . '/storage');
            $public = realpath($root . '/public');
            $key = static fn(string $path): string => strtolower(str_replace('\\', '/', $path));

            if (
                $storage === false || $public === false
                || str_starts_with($key($storage) . '/', $key($public) . '/')
            ) {
                return;
            }

            $directory = $storage . '/tasks';

            if (
                is_link($directory) || (!is_dir($directory) && !mkdir($directory, 0700))
                || (DIRECTORY_SEPARATOR === '/' && (fileperms($directory) & 0077) !== 0)
                || is_link($directory . '/tick')
            ) {
                return;
            }

            // Most requests arrive before the next tick is due. The locked check below still decides who runs it.
            $lastRun = self::lastRun($storage);
            if ($lastRun !== null && $lastRun > time() - self::INTERVAL_SECONDS) {
                return;
            }

            $lock = fopen($directory . '/tick', 'c+b');

            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                return;
            }

            chmod($directory . '/tick', 0600);
            $last = stream_get_contents($lock, 32);

            if (is_string($last) && ctype_digit(trim($last)) && (int) $last > time() - self::INTERVAL_SECONDS) {
                return;
            }

            rewind($lock);

            if (!ftruncate($lock, 0) || fwrite($lock, (string) time()) === false || !fflush($lock)) {
                return;
            }

            $config = ConfigLoader::load($root . '/config/local.php', $environment);

            if ($config === null || !in_array($config->databaseDriver, ['mysql', 'mariadb', 'postgresql'], true)) {
                return;
            }

            $db = ConnectionFactory::create($config);

            try {
                $ready = $db->fetchOne(
                    'SELECT COUNT(*) FROM migration_history WHERE id IN (?, ?, ?, ?, ?, ?) AND status = ?',
                    ['0035_account_deletion_progress',
                        '0036_admin_saved_filters',
                        '0037_forum_moderators',
                        '0038_users_appearance',
                        '0039_extension_contracts',
                        '0040_community',
                        'applied'],
                );

                if ((int) $ready !== 6) {
                    return;
                }

                $released = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
                (new TaskRuntime($db, $config, $root, $storage))->run($released ? 10 : 3, $released ? 3000 : 1000);
            } finally {
                $db->close();
            }
        } catch (Throwable) {
            // Response is already sent. Never expose payloads, configuration or credential-bearing exceptions.
            error_log('nolviko_background_tick_failed');
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
