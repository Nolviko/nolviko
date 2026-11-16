<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Nolviko\Modules\Importers\Domain\ImportAsset;
use RuntimeException;

/** Only platform-default attachment names under an owner-configured read-only root; never URLs or source PHP. */
final readonly class LocalImportAsset implements ImportAsset
{
    public const MAX_BYTES = 33554432;

    private string $path;

    public function __construct(string $root, string $name, private int $bytes)
    {
        $base = realpath($root);
        $path = realpath($root . '/' . $name);
        if (
            $base === false || $path === false || is_link($root) || !is_file($path) || is_link($root . '/' . $name)
            || !str_starts_with($path, rtrim($base, '/\\') . DIRECTORY_SEPARATOR)
            || $bytes < 0 || $bytes > self::MAX_BYTES || filesize($path) !== $bytes
        ) {
            throw new RuntimeException('import_attachment_invalid');
        }
        $current = $root;
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '\\')) {
                throw new RuntimeException('import_attachment_invalid');
            }
            $current .= '/' . $part;
            if (is_link($current)) {
                throw new RuntimeException('import_attachment_invalid');
            }
        }
        $this->path = $path;
    }

    public function size(): int
    {
        return $this->bytes;
    }

    public function digest(): string
    {
        $stream = $this->open();
        $hash = hash_init('sha256');
        try {
            $read = hash_update_stream($hash, $stream, $this->bytes + 1);
            if ($read !== $this->bytes) {
                throw new RuntimeException('import_attachment_changed');
            }
            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    public function copy(mixed $destination): void
    {
        if (!is_resource($destination)) {
            throw new RuntimeException('import_attachment_invalid');
        }
        $expected = $this->digest();
        $stream = $this->open();
        $hash = hash_init('sha256');
        $total = 0;
        $deadline = microtime(true) + 2;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && !feof($stream)) || microtime(true) > $deadline) {
                    throw new RuntimeException('import_attachment_copy_failed');
                }
                $total += strlen($chunk);
                if ($total > $this->bytes) {
                    throw new RuntimeException('import_attachment_changed');
                }
                hash_update($hash, $chunk);
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($destination, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('import_attachment_copy_failed');
                    }
                    $offset += $written;
                }
            }
            if ($total !== $this->bytes || !hash_equals($expected, hash_final($hash))) {
                throw new RuntimeException('import_attachment_changed');
            }
        } finally {
            fclose($stream);
        }
    }

    private function open(): mixed
    {
        $before = lstat($this->path);
        if ($before === false || is_link($this->path) || !is_file($this->path) || $before['size'] !== $this->bytes) {
            throw new RuntimeException('import_attachment_changed');
        }
        $stream = @fopen($this->path, 'rb');
        $stat = is_resource($stream) ? fstat($stream) : false;
        if (
            $stat === false || ($stat['mode'] & 0170000) !== 0100000 || $before['ino'] !== $stat['ino']
            || $before['dev'] !== $stat['dev'] || $stat['size'] !== $this->bytes
        ) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new RuntimeException('import_attachment_changed');
        }
        return $stream;
    }
}
