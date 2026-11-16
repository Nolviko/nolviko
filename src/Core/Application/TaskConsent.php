<?php

declare(strict_types=1);

namespace Nolviko\Core\Application;

use RuntimeException;

/** CLI execution is opt-in through a private server-side marker; HTTP cannot set it without admin guards. */
final class TaskConsent
{
    public function __construct(private readonly string $storage)
    {
    }

    public function enabled(): bool
    {
        return !is_link($this->storage . '/tasks') && !is_link($this->path()) && is_file($this->path());
    }

    public function set(bool $enabled): void
    {
        $directory = $this->storage . '/tasks';

        if (
            !is_dir($this->storage) || is_link($directory) || is_link($this->path())
            || (!is_dir($directory) && !mkdir($directory, 0700))
        ) {
            throw new RuntimeException('tasks_storage_invalid');
        }

        if (!$enabled) {
            if (is_file($this->path()) && !unlink($this->path())) {
                throw new RuntimeException('tasks_storage_invalid');
            }

            return;
        }

        if (file_put_contents($this->path(), "enabled\n", LOCK_EX) !== 8 || !chmod($this->path(), 0600)) {
            throw new RuntimeException('tasks_storage_invalid');
        }
    }

    private function path(): string
    {
        return $this->storage . '/tasks/cli.enabled';
    }
}
