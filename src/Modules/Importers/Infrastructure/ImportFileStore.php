<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Nolviko\Modules\Importers\Domain\ImportAsset;
use RuntimeException;

/** Legacy originals are download-only, never decoded/executed. Deterministic private keys make crash retries safe. */
final readonly class ImportFileStore
{
    public function __construct(private string $storage, private string $public)
    {
    }

    public function retain(string $job, int $source, ImportAsset $asset): array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $job) !== 1 || $source < 1) {
            throw new RuntimeException('import_attachment_invalid');
        }
        $key = hash('sha256', 'import-attachment:' . $job . ':' . $source);
        $path = $this->path($key);
        $hash = $asset->digest();
        if (is_file($path)) {
            if (
                is_link($path) || filesize($path) !== $asset->size()
                || !hash_equals($hash, (string) hash_file('sha256', $path))
            ) {
                throw new RuntimeException('import_attachment_changed');
            }
            return ['key' => $key, 'sha256' => $hash, 'bytes' => $asset->size()];
        }
        $temporary = $path . '.pending';
        if (is_link($temporary)) {
            throw new RuntimeException('import_attachment_invalid');
        }
        $stream = @fopen($temporary, 'c+b');
        if ($stream === false) {
            throw new RuntimeException('import_attachment_copy_failed');
        }
        try {
            if (!chmod($temporary, 0600) || !flock($stream, LOCK_EX | LOCK_NB) || !ftruncate($stream, 0)) {
                throw new RuntimeException('import_attachment_copy_failed');
            }
            $asset->copy($stream);
            if (!fflush($stream)) {
                throw new RuntimeException('import_attachment_copy_failed');
            }
        } finally {
            fclose($stream);
        }
        if (
            filesize($temporary) !== $asset->size()
            || !hash_equals($hash, (string) hash_file('sha256', $temporary))
            || is_link($path) || file_exists($path) || !rename($temporary, $path)
        ) {
            throw new RuntimeException('import_attachment_copy_failed');
        }
        return ['key' => $key, 'sha256' => $hash, 'bytes' => $asset->size()];
    }

    public function path(string $key): string
    {
        $root = realpath($this->storage);
        $public = realpath($this->public);
        if (
            preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || $root === false || $public === false
            || is_link($this->storage)
            || str_starts_with(
                strtolower(str_replace('\\', '/', $root)) . '/',
                strtolower(str_replace('\\', '/', $public)) . '/'
            )
        ) {
            throw new RuntimeException('import_private_storage_required');
        }
        $directory = $root . '/community-media';
        foreach ([$directory, $directory . '/' . substr($key, 0, 2)] as $part) {
            if (is_link($part) || (!is_dir($part) && !mkdir($part, 0700))) {
                throw new RuntimeException('import_private_storage_required');
            }
        }
        $path = $directory . '/' . substr($key, 0, 2) . '/' . $key;
        if (is_link($path)) {
            throw new RuntimeException('import_attachment_invalid');
        }
        return $path;
    }
}
