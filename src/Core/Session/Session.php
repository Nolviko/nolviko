<?php

declare(strict_types=1);

namespace Nolviko\Core\Session;

/**
 * The current visitor's session, started only when needed.
 *
 * Reading never starts a new session: without a session cookie `get()` returns the default. Writing starts
 * one. Values must be scalars or arrays of them.
 *
 * Contract for authentication (M02.04): call `regenerate()` after login and after any privilege change, and
 * `invalidate()` on logout; both replace the session ID (the old one is deleted, so a fixed or stolen ID is
 * worthless afterwards).
 */
interface Session
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    /**
     * New session ID, same data (login, privilege change).
     */
    public function regenerate(): void;

    /**
     * New session ID and no data, including the CSRF secret (logout).
     */
    public function invalidate(): void;

    public function isStarted(): bool;
}
