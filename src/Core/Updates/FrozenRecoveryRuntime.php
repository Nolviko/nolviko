<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Copy only control-runtime code, never installed secrets/uploads. No live file is modified. */
final class FrozenRecoveryRuntime
{
    public function __construct(private readonly string $source, private readonly string $private)
    {
    }

    /** @return array{complete: bool, copied: int, total: int} */
    public function prepare(string $job, int $budget = 64): array
    {
        $source = realpath($this->source);
        $private = realpath($this->private);

        if (
            $source === false || $private === false || is_link($this->source) || is_link($this->private)
            || preg_match('/^[a-f0-9]{32}$/D', $job) !== 1 || $budget < 1 || $budget > 128
            || str_starts_with(self::key($private) . '/', self::key($source) . '/')
            || (DIRECTORY_SEPARATOR === '/' && (fileperms($private) & 0077) !== 0)
        ) {
            throw new RuntimeException('recovery_snapshot_configuration_invalid');
        }

        $directory = $private . '/frozen-' . $job;

        if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
            throw new RuntimeException('recovery_snapshot_path_invalid');
        }

        if (!is_dir($directory) && !mkdir($directory, 0700)) {
            throw new RuntimeException('recovery_snapshot_unwritable');
        }

        $lockPath = $directory . '/lock';
        self::regular($lockPath);
        $lock = fopen($lockPath, 'c+b');

        if ($lock === false) {
            throw new RuntimeException('recovery_snapshot_unwritable');
        }

        try {
            if (!chmod($lockPath, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('recovery_snapshot_busy');
            }

            $metadata = $directory . '/manifest.json';
            $runtime = $directory . '/runtime';
            self::regular($metadata);

            if (is_link($runtime)) {
                throw new RuntimeException('recovery_snapshot_path_invalid');
            }

            if (!is_file($metadata)) {
                if (is_dir($runtime) && (new FilesystemIterator($runtime))->valid()) {
                    throw new RuntimeException('recovery_snapshot_state_missing');
                }

                if (!is_dir($runtime) && !mkdir($runtime, 0700)) {
                    throw new RuntimeException('recovery_snapshot_unwritable');
                }

                $state = ['source' => $source, 'items' => self::inventory($source), 'next' => 0, 'ready' => false];
                self::save($metadata, $state);
            } else {
                if (filesize($metadata) > 4194304) {
                    throw new RuntimeException('recovery_snapshot_state_invalid');
                }

                $state = json_decode((string) file_get_contents($metadata), true, 8, JSON_THROW_ON_ERROR);
            }

            if (
                !is_array($state) || ($state['source'] ?? null) !== $source || !is_array($state['items'] ?? null)
                || count($state['items']) > 10000 || !is_int($state['next'] ?? null) || $state['next'] < 0
                || $state['next'] > count($state['items']) || !is_bool($state['ready'] ?? null)
                || ($state['ready'] && $state['next'] !== count($state['items']))
            ) {
                throw new RuntimeException('recovery_snapshot_state_invalid');
            }

            $deadline = microtime(true) + 5;
            $count = 0;

            while (!$state['ready'] && $state['next'] < count($state['items']) && $count < $budget) {
                if ($count > 0 && microtime(true) > $deadline) {
                    break;
                }

                $item = $state['items'][$state['next']];
                self::assertItem($item);
                FileSwitchPlan::regularParents($source, $item['path']);
                FileSwitchPlan::regularParents($runtime, $item['path']);
                $target = $runtime . '/' . $item['path'];

                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true)) {
                    throw new RuntimeException('recovery_snapshot_unwritable');
                }

                $temporary = $directory . '/pending';
                self::regular($temporary);

                if (
                    !copy($source . '/' . $item['path'], $temporary) || !chmod($temporary, 0600)
                    || hash_file('sha256', $temporary) !== $item['hash'] || !rename($temporary, $target)
                ) {
                    throw new RuntimeException('recovery_snapshot_copy_failed');
                }

                ++$state['next'];
                ++$count;
                self::save($metadata, $state);
            }

            if ($state['next'] === count($state['items'])) {
                foreach ($state['items'] as $item) {
                    self::assertItem($item);
                    FileSwitchPlan::regularParents($runtime, $item['path']);

                    if (
                        !is_file($runtime . '/' . $item['path'])
                        || hash_file('sha256', $runtime . '/' . $item['path']) !== $item['hash']
                        || (!$state['ready'] && hash_file('sha256', $source . '/' . $item['path']) !== $item['hash'])
                    ) {
                        throw new RuntimeException('recovery_snapshot_integrity_failed');
                    }
                }

                if (
                    !is_file($runtime . '/vendor/autoload.php')
                    || !is_file($runtime . '/src/Core/Http/RecoveryGateway.php')
                    || !is_file($runtime . '/src/Core/Http/FrozenRecoveryEntry.php')
                ) {
                    throw new RuntimeException('recovery_snapshot_incomplete');
                }

                $state['ready'] = true;
                self::save($metadata, $state);
            }

            return ['complete' => $state['ready'], 'copied' => $state['next'], 'total' => count($state['items'])];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<array{path: string, hash: string}> */
    private static function inventory(string $root): array
    {
        $paths = ['config/bootstrap.php', 'config/runtime-policy.php'];
        $nodes = 0;

        foreach (['src', 'vendor', 'views', 'translations'] as $tree) {
            if (is_link($root . '/' . $tree) || !is_dir($root . '/' . $tree)) {
                throw new RuntimeException('recovery_snapshot_source_invalid');
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
                $root . '/' . $tree,
                FilesystemIterator::SKIP_DOTS,
            ), RecursiveIteratorIterator::SELF_FIRST);

            foreach ($iterator as $entry) {
                if (++$nodes > 20000 || $entry->isLink()) {
                    throw new RuntimeException('recovery_snapshot_source_invalid');
                }

                if ($entry->isFile()) {
                    $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($root) + 1));
                }
            }
        }

        if (count($paths) > 10000) {
            throw new RuntimeException('recovery_snapshot_budget');
        }

        sort($paths, SORT_STRING);
        $items = [];
        $bytes = 0;

        foreach ($paths as $path) {
            ArchivePath::normalize($path);
            FileSwitchPlan::regularParents($root, $path);
            $bytes += filesize($root . '/' . $path);

            if ($bytes > 67108864) {
                throw new RuntimeException('recovery_snapshot_budget');
            }

            $hash = hash_file('sha256', $root . '/' . $path);

            if ($hash === false) {
                throw new RuntimeException('recovery_snapshot_source_invalid');
            }

            $items[] = ['path' => $path, 'hash' => $hash];
        }

        return $items;
    }

    /** @param array<string, mixed> $item */
    private static function assertItem(array $item): void
    {
        $path = $item['path'] ?? null;

        if (
            !is_string($path) || ArchivePath::normalize($path) !== $path
            || !is_string($item['hash'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $item['hash']) !== 1
            || preg_match(
                '~^(?:src/|vendor/|views/|translations/|config/(?:bootstrap|runtime-policy)\.php$)~',
                $path,
            ) !== 1
        ) {
            throw new RuntimeException('recovery_snapshot_state_invalid');
        }
    }

    /** @param array<string, mixed> $state */
    private static function save(string $path, array $state): void
    {
        $temporary = $path . '.tmp';
        self::regular($temporary);
        $stream = fopen($temporary, 'w+b');

        if ($stream === false) {
            throw new RuntimeException('recovery_snapshot_unwritable');
        }

        try {
            $bytes = json_encode($state, JSON_THROW_ON_ERROR);

            if (
                strlen($bytes) > 4194304 || !chmod($temporary, 0600)
                || fwrite($stream, $bytes) !== strlen($bytes) || !fsync($stream)
            ) {
                throw new RuntimeException('recovery_snapshot_unwritable');
            }
        } finally {
            fclose($stream);
        }

        if (!rename($temporary, $path)) {
            throw new RuntimeException('recovery_snapshot_unwritable');
        }
    }

    private static function key(string $path): string
    {
        return rtrim(strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $path)), '/');
    }

    private static function regular(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('recovery_snapshot_path_invalid');
        }
    }
}
