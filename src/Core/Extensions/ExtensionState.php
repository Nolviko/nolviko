<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use Closure;

/** Atomic private configuration and an operator-controlled safe-mode switch, before any PHP providers load. */
final readonly class ExtensionState
{
    public function __construct(
        public string $directory,
    ) {
    }

    public function safe(): bool
    {
        return file_exists($this->directory . '/safe-mode') || is_link($this->directory . '/safe-mode');
    }

    /** @return array<string, array{version: string, digest: string}> */
    public function enabled(): array
    {
        $file = $this->directory . '/enabled.json';
        if (!file_exists($file)) {
            return [];
        }

        if (is_link($file) || filesize($file) > 65536) {
            throw new ExtensionFailure('extension_state_invalid');
        }

        $data = json_decode((string) file_get_contents($file), true, 10);
        if (!is_array($data) || count($data) > 128) {
            throw new ExtensionFailure('extension_state_invalid');
        }

        foreach ($data as $id => $entry) {
            if (
                !is_string($id) || preg_match(ExtensionManifest::ID_PATTERN, $id) !== 1 || !is_array($entry)
                || !is_string($entry['version'] ?? null) || !is_string($entry['digest'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $entry['digest']) !== 1
            ) {
                throw new ExtensionFailure('extension_state_invalid');
            }
        }

        return $data;
    }

    /** @param Closure(): mixed $operation */
    public function locked(Closure $operation): mixed
    {
        $this->prepare();
        $path = $this->directory . '/lifecycle.lock';
        if (is_link($path)) {
            throw new ExtensionFailure('extension_state_invalid');
        }

        $lock = fopen($path, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new ExtensionFailure('extension_busy');
        }

        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, array{version: string, digest: string}> $enabled */
    public function write(array $enabled): void
    {
        $this->prepare();
        ksort($enabled);
        $temporary = $this->directory . '/state-' . bin2hex(random_bytes(12)) . '.tmp';
        $body = json_encode((object) $enabled, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
        if (file_put_contents($temporary, $body, LOCK_EX) !== strlen($body)) {
            throw new ExtensionFailure('extension_state_write_failed');
        }

        chmod($temporary, 0600);
        if (!rename($temporary, $this->directory . '/enabled.json')) {
            unlink($temporary);
            throw new ExtensionFailure('extension_state_write_failed');
        }
    }

    public function rememberFailure(): void
    {
        $this->prepare();
        $file = $this->directory . '/boot-failed';
        if (is_link($file) || file_put_contents($file, 'extension_boot_failed', LOCK_EX) !== 21) {
            throw new ExtensionFailure('extension_state_write_failed');
        }

        chmod($file, 0600);
    }

    public function hasBootFailure(): bool
    {
        return is_file($this->directory . '/boot-failed');
    }

    public function setSafe(bool $safe): void
    {
        $this->prepare();
        $path = $this->directory . '/safe-mode';
        if (is_link($path)) {
            throw new ExtensionFailure('extension_state_invalid');
        }

        if ($safe && file_put_contents($path, '1', LOCK_EX) !== 1) {
            throw new ExtensionFailure('extension_state_write_failed');
        }

        if (!$safe && is_file($path) && !unlink($path)) {
            throw new ExtensionFailure('extension_state_write_failed');
        }
    }

    private function prepare(): void
    {
        if (is_link($this->directory)) {
            throw new ExtensionFailure('extension_state_invalid');
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new ExtensionFailure('extension_state_write_failed');
        }
    }
}
