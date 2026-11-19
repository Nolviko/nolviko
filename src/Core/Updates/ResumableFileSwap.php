<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use Closure;
use JsonException;
use RuntimeException;

/**
 * Per-file atomic promotion, with private progress and a stable control runtime outside the managed application.
 */
final class ResumableFileSwap
{
    public const MAX_STEP_ENTRIES = 32;

    public const MAX_STEP_BYTES = 4194304;

    private readonly string $live;

    private readonly string $private;

    public function __construct(string $liveRoot, string $privateRoot)
    {
        $live = realpath($liveRoot);
        $private = realpath($privateRoot);

        if (
            $live === false || $private === false || !is_dir($live) || !is_dir($private)
            || is_link($liveRoot) || is_link($privateRoot)
        ) {
            throw new RuntimeException('update_switch_roots_invalid');
        }

        $liveKey = self::key($live);
        $privateKey = self::key($private);

        // Inside the application only under its storage/ (2026-11-19, found by the end-to-end update drill): the
        // default install keeps storage/ there, and FileSwitchPlan never replaces storage/ (protected paths).
        $storageKey = $liveKey . '/storage/';
        $outsideStorage = static fn(string $key): bool => !str_starts_with($key . '/', $storageKey);
        if (
            (str_starts_with($privateKey . '/', $liveKey . '/') && $outsideStorage($privateKey))
            || str_starts_with($liveKey . '/', $privateKey . '/')
            || (str_starts_with(self::key(__FILE__) . '/', $liveKey . '/') && $outsideStorage(self::key(__FILE__)))
        ) {
            throw new RuntimeException('update_switch_control_not_isolated');
        }

        if (stat($live)['dev'] !== stat($private)['dev']) {
            throw new RuntimeException('update_switch_filesystem_mismatch');
        }

        if (DIRECTORY_SEPARATOR === '/' && (fileperms($private) & 0077) !== 0) {
            throw new RuntimeException('update_switch_private_permissions');
        }

        if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL) && !function_exists('opcache_invalidate')) {
            throw new RuntimeException('update_switch_opcache_unavailable');
        }

        $this->live = $live;
        $this->private = $private;
    }

    public function step(
        FileSwitchPlan $plan,
        string $job,
        Closure $guard,
        int $budget = self::MAX_STEP_ENTRIES,
    ): FileSwapProgress {
        if (preg_match('/^[a-f0-9]{32}$/D', $job) !== 1 || $budget < 1 || $budget > self::MAX_STEP_ENTRIES) {
            throw new RuntimeException('update_switch_request_invalid');
        }

        if (!str_starts_with(self::key($plan->staging) . '/', self::key($this->private) . '/')) {
            throw new RuntimeException('update_switch_stage_is_live');
        }

        $guard();
        $this->maintenance();
        $directory = $this->private . '/switch-' . $job;

        if (is_link($directory) || (file_exists($directory) && !is_dir($directory))) {
            throw new RuntimeException('update_switch_state_invalid');
        }

        if (!is_dir($directory) && !mkdir($directory, 0700)) {
            throw new RuntimeException('update_switch_state_unwritable');
        }

        $lockPath = $directory . '/lock';
        self::regular($lockPath);
        $lock = fopen($lockPath, 'c+b');

        if ($lock === false) {
            throw new RuntimeException('update_switch_lock_failed');
        }

        try {
            if (!chmod($lockPath, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('update_switch_busy');
            }

            return $this->apply($plan, $directory, $guard, $budget);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function apply(FileSwitchPlan $plan, string $directory, Closure $guard, int $budget): FileSwapProgress
    {
        clearstatcache();
        $identity = hash('sha256', $plan->hash . "\0" . $this->live);
        $statePath = $directory . '/progress.json';
        self::regular($statePath);
        $index = 0;
        $started = $directory . '/started';
        self::regular($started);

        if (!is_file($statePath) && file_exists($started)) {
            throw new RuntimeException('update_switch_checkpoint_missing');
        }

        if (is_file($statePath)) {
            if (filesize($statePath) > 4096) {
                throw new RuntimeException('update_switch_state_invalid');
            }

            $body = file_get_contents($statePath);

            if ($body === false) {
                throw new RuntimeException('update_switch_state_unreadable');
            }

            try {
                $state = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('update_switch_state_invalid');
            }

            if (
                ($state['identity'] ?? null) !== $identity || !is_int($state['next'] ?? null)
                || $state['next'] < 0 || $state['next'] > count($plan->operations)
            ) {
                throw new RuntimeException('update_switch_state_identity');
            }

            $index = $state['next'];
        }

        // Validate the entire target before changing it, and the completed prefix on every restart.
        foreach ($plan->operations as $offset => $operation) {
            FileSwitchPlan::regularParents($this->live, $operation['path']);
            $target = $this->live . '/' . $operation['path'];
            $current = self::digest($target);

            if ($offset < $index) {
                if ($current !== $operation['hash']) {
                    throw new RuntimeException('update_switch_completed_file_changed');
                }
            } elseif ($current !== null && $current !== $operation['hash'] && $current !== $operation['old']) {
                throw new RuntimeException('update_switch_local_change');
            }
        }

        if (!is_file($statePath)) {
            self::checkpoint($directory, ['identity' => $identity, 'next' => 0]);
        }

        if (!file_exists($started)) {
            $marker = fopen($started, 'x+b');

            if ($marker === false) {
                throw new RuntimeException('update_switch_checkpoint_failed');
            }

            try {
                if (!chmod($started, 0600) || fwrite($marker, $identity) !== strlen($identity) || !fsync($marker)) {
                    throw new RuntimeException('update_switch_checkpoint_failed');
                }
            } finally {
                fclose($marker);
            }
        }

        $deadline = microtime(true) + 8;
        $bytes = 0;
        $processed = 0;

        while ($index < count($plan->operations) && $processed < $budget) {
            if ($processed > 0 && ($bytes >= self::MAX_STEP_BYTES || microtime(true) > $deadline)) {
                break;
            }

            $guard();
            $this->maintenance();
            $operation = $plan->operations[$index];
            $target = $this->live . '/' . $operation['path'];
            FileSwitchPlan::regularParents($this->live, $operation['path']);
            $current = self::digest($target);

            if ($current !== $operation['hash']) {
                if ($current !== null && $current !== $operation['old']) {
                    throw new RuntimeException('update_switch_local_change');
                }

                if ($operation['hash'] === null) {
                    $guard();
                    $this->maintenance();

                    $latest = self::digest($target);

                    if ($latest !== null && $latest !== $operation['old']) {
                        throw new RuntimeException('update_switch_local_change');
                    }

                    if ($latest !== null && !unlink($target)) {
                        throw new RuntimeException('update_switch_remove_failed');
                    }
                } else {
                    $this->copy($plan, $operation, $directory, $target, $guard);
                }
            }

            // Invalidation is repeated even when replay observes a rename completed before its checkpoint.
            if (function_exists('opcache_invalidate')) {
                $cached = function_exists('opcache_is_script_cached') && opcache_is_script_cached($target);
                $invalidated = opcache_invalidate($target, true);

                if ($cached && !$invalidated) {
                    throw new RuntimeException('update_switch_opcache_failed');
                }
            }

            $guard();
            $this->maintenance();
            ++$index;
            ++$processed;
            $bytes += $operation['size'];
            self::checkpoint($directory, ['identity' => $identity, 'next' => $index]);
        }

        return new FileSwapProgress($index, $index === count($plan->operations));
    }

    /**
     * @param array{path: string, hash: string|null, old: string|null, size: int} $operation
     */
    private function copy(
        FileSwitchPlan $plan,
        array $operation,
        string $directory,
        string $target,
        Closure $guard,
    ): void {
        FileSwitchPlan::regularParents($plan->staging, $operation['path']);
        $source = $plan->staging . '/' . $operation['path'];

        if (!is_file($source) || filesize($source) !== $operation['size']) {
            throw new RuntimeException('update_switch_stage_changed');
        }

        $temporary = $directory . '/pending';
        self::regular($temporary);
        $input = fopen($source, 'rb');
        $output = fopen($temporary, 'w+b');

        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }

            if (is_resource($output)) {
                fclose($output);
            }

            throw new RuntimeException('update_switch_copy_failed');
        }

        try {
            if (
                !chmod($temporary, 0600)
                || stream_copy_to_stream($input, $output, $operation['size'] + 1) !== $operation['size']
                || !fsync($output)
            ) {
                throw new RuntimeException('update_switch_copy_failed');
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        if (hash_file('sha256', $temporary) !== $operation['hash']) {
            throw new RuntimeException('update_switch_stage_changed');
        }

        $parent = dirname($target);

        if (!is_dir($parent) && !mkdir($parent, 0755, true)) {
            throw new RuntimeException('update_switch_parent_failed');
        }

        FileSwitchPlan::regularParents($this->live, $operation['path']);

        if (stat($parent)['dev'] !== stat($directory)['dev']) {
            throw new RuntimeException('update_switch_filesystem_mismatch');
        }

        $guard();
        $this->maintenance();
        $current = self::digest($target);

        if ($current !== null && $current !== $operation['old'] && $current !== $operation['hash']) {
            throw new RuntimeException('update_switch_local_change');
        }

        if (!chmod($temporary, 0644) || !rename($temporary, $target)) {
            throw new RuntimeException('update_switch_rename_failed');
        }
    }

    private function maintenance(): void
    {
        if (!file_exists($this->live . '/config/maintenance.lock')) {
            throw new RuntimeException('update_switch_maintenance_required');
        }
    }

    /**
     * @param array{identity: string, next: int} $state
     */
    private static function checkpoint(string $directory, array $state): void
    {
        $temporary = $directory . '/progress.tmp';
        self::regular($temporary);
        $stream = fopen($temporary, 'w+b');

        if ($stream === false) {
            throw new RuntimeException('update_switch_checkpoint_failed');
        }

        try {
            $bytes = json_encode($state, JSON_THROW_ON_ERROR);

            if (!chmod($temporary, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fsync($stream)) {
                throw new RuntimeException('update_switch_checkpoint_failed');
            }
        } finally {
            fclose($stream);
        }

        if (!rename($temporary, $directory . '/progress.json')) {
            throw new RuntimeException('update_switch_checkpoint_failed');
        }
    }

    private static function digest(string $path): ?string
    {
        if (!file_exists($path)) {
            return null;
        }

        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('update_switch_target_invalid');
        }

        return hash_file('sha256', $path) ?: throw new RuntimeException('update_switch_target_unreadable');
    }

    private static function regular(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('update_switch_state_invalid');
        }
    }

    private static function key(string $path): string
    {
        return rtrim(strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $path)), '/');
    }
}
