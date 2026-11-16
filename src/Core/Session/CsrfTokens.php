<?php

declare(strict_types=1);

namespace Nolviko\Core\Session;

/**
 * CSRF tokens bound to a per-session secret.
 *
 * Every `token()` is the secret masked with fresh random bytes (`mask . (mask XOR secret)`, base64url), so
 * the bytes on a compressed page differ on each render (BREACH) while any of them stays valid for the whole
 * session. Forms send it in the `_csrf_token` field, scripts in the `X-CSRF-Token` header; CsrfGuard checks it.
 */
final class CsrfTokens
{
    public const FIELD = '_csrf_token';

    public const HEADER = 'X-CSRF-Token';

    private const SESSION_KEY = '_csrf_secret';

    private const SECRET_BYTES = 32;

    public function __construct(
        private readonly Session $session,
    ) {
    }

    /**
     * A token for a form or script; creates the secret (and so the session) on first use.
     */
    public function token(): string
    {
        $secret = $this->secret();

        if ($secret === null) {
            $secret = random_bytes(self::SECRET_BYTES);
            $this->session->set(self::SESSION_KEY, $secret);
        }

        $mask = random_bytes(self::SECRET_BYTES);

        return rtrim(strtr(base64_encode($mask . ($mask ^ $secret)), '+/', '-_'), '=');
    }

    /**
     * Never creates a session: without one there is no secret and every token is invalid.
     */
    public function isValid(?string $token): bool
    {
        $secret = $token === null || $token === '' ? null : $this->secret();

        if ($secret === null) {
            return false;
        }

        $decoded = base64_decode(strtr((string) $token, '-_', '+/'), true);

        if ($decoded === false || strlen($decoded) !== 2 * self::SECRET_BYTES) {
            return false;
        }

        $mask = substr($decoded, 0, self::SECRET_BYTES);

        return hash_equals($secret, $mask ^ substr($decoded, self::SECRET_BYTES));
    }

    /**
     * A new secret, so tokens issued before (for example before login) stop working.
     */
    public function rotate(): void
    {
        $this->session->set(self::SESSION_KEY, random_bytes(self::SECRET_BYTES));
    }

    private function secret(): ?string
    {
        $secret = $this->session->get(self::SESSION_KEY);

        return is_string($secret) && strlen($secret) === self::SECRET_BYTES ? $secret : null;
    }
}
