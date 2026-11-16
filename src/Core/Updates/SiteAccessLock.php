<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

/** Shared request/task access and exclusive updater capture; never delete or rotate this coordination file. */
final readonly class SiteAccessLock
{
    public function __construct(private string $storage)
    {
    }

    public function acquire(bool $exclusive): mixed
    {
        $path = $this->storage . '/deployment.lock';
        if (!is_dir($this->storage) || is_link($this->storage) || is_link($path)) {
            return false;
        }
        $stream = @fopen($path, 'c+b');
        if ($stream === false) {
            return false;
        }
        if (!@chmod($path, 0600) || !flock($stream, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
            fclose($stream);
            return false;
        }
        return $stream;
    }

    public static function release(mixed $stream): void
    {
        if (is_resource($stream)) {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }
}
