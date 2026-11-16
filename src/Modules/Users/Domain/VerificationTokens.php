<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use DateTimeImmutable;

/**
 * Email verification tokens (M02.03): 256 random bits sent once, stored as SHA-256, 24 hours, single use.
 */
interface VerificationTokens
{
    public const LIFETIME = '+24 hours';

    /**
     * @return string The token for the link; it is not stored anywhere in readable form.
     */
    public function issue(int $userId, string $email, DateTimeImmutable $now): string;

    /**
     * Uses the token and moves its pending account to `$verified` (active, or pending approval, M02.03a). A
     * token is refused when it is unknown (or tampered), used, expired, or was issued for an address the
     * account no longer has.
     *
     * @return int|null The verified user's ID, or null when the token is refused.
     */
    public function consume(
        string $token,
        DateTimeImmutable $now,
        UserStatus $verified = UserStatus::Active,
    ): ?int;
}
