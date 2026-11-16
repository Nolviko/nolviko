<?php

declare(strict_types=1);

namespace Nolviko\Core\Application;

use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Database\ConnectionFactory;
use RuntimeException;
use Throwable;

/** Explicit private CLI consent and finite batches; no default daemon, shell calls or command from HTTP. */
final class TaskCli
{
    public static function run(string $root, array $environment, array $arguments): int
    {
        try {
            $storage = $environment['FORUM_STORAGE_DIR'] ?? $root . '/storage';
            $real = realpath($storage);
            $public = realpath($root . '/public');

            if (
                $real === false || $public === false || is_link($storage)
                || str_starts_with(
                    strtolower(str_replace('\\', '/', $real)) . '/',
                    strtolower(str_replace('\\', '/', $public)) . '/'
                )
            ) {
                throw new RuntimeException('tasks_storage_invalid');
            }

            $consent = new TaskConsent($storage);
            $mode = '--run';
            $limit = 3;
            $milliseconds = 2000;

            foreach ($arguments as $argument) {
                if (in_array($argument, ['--enable', '--disable', '--run'], true)) {
                    $mode = $argument;
                } elseif (preg_match('/^--limit=([0-9]{1,2})$/D', $argument, $match) === 1) {
                    $limit = (int) $match[1];
                } elseif (preg_match('/^--milliseconds=([0-9]{1,4})$/D', $argument, $match) === 1) {
                    $milliseconds = (int) $match[1];
                } else {
                    throw new RuntimeException('tasks_arguments_invalid');
                }
            }

            if ($limit < 1 || $limit > 10 || $milliseconds < 10 || $milliseconds > 5000) {
                throw new RuntimeException('tasks_arguments_invalid');
            }

            if ($mode !== '--run') {
                $consent->set($mode === '--enable');
                echo json_encode(['enabled' => $consent->enabled()], JSON_THROW_ON_ERROR) . "\n";

                return 0;
            }

            if (!$consent->enabled() || !is_file($root . '/config/install.lock')) {
                throw new RuntimeException('tasks_cli_not_enabled');
            }

            $config = ConfigLoader::load($root . '/config/local.php', $environment)
                ?? throw new RuntimeException('tasks_unavailable');
            $db = ConnectionFactory::create($config);

            try {
                $result = (new TaskRuntime($db, $config, $root, $storage))->run($limit, $milliseconds);
                echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
            } finally {
                $db->close();
            }

            return $result['failed'] > 0 ? 1 : 0;
        } catch (Throwable) {
            // Stable diagnostic only; command input, file paths, payloads and secrets never reach output.
            fwrite(STDERR, "tasks_cli_failed\n");

            return 1;
        }
    }
}
