<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use SensitiveParameter;

/**
 * "Remember me" tokens in selector/validator form: the cookie carries `selector:validator`; storage keeps the
 * selector and a SHA-256 of the validator only, so a leaked table cannot be replayed.
 */
interface RememberTokens
{
    public const LIFETIME_DAYS = 30;

    /**
     * @return string The cookie value for the new token.
     */
    public function issue(int $userId): string;

    /**
     * Uses a token once: on success it is replaced by a new one (rotation). A known selector with a wrong
     * validator means the token was stolen and copied: all of that user's tokens are revoked.
     *
     * @return array{0: int, 1: string, 2?: int}|null User ID and the replacement cookie value, or null.
     */
    public function consume(#[SensitiveParameter] string $cookie): ?array;

    public function revoke(#[SensitiveParameter] string $cookie): void;

    /**
     * Logout everywhere and password change.
     */
    public function revokeAll(int $userId): void;
}
