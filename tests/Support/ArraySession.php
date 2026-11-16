<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Nolviko\Core\Session\Session;

/**
 * In-memory Session for unit tests; the native one needs a fresh process (Contract\NativeSessionTest).
 */
final class ArraySession implements Session
{
    /** @var array<string, mixed> */
    public array $data = [];

    public int $regenerations = 0;

    public bool $started = false;

    public function get(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->started = true;
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        $this->started = true;
        ++$this->regenerations;
    }

    public function invalidate(): void
    {
        $this->data = [];
        $this->regenerate();
    }

    public function isStarted(): bool
    {
        return $this->started;
    }
}
