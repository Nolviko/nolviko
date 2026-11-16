<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use DateTimeImmutable;
use SensitiveParameter;

/**
 * Password reset tokens (M02.05): 256 random bits sent once, stored as SHA-256, one hour, single use.
 */
interface ResetTokens
{
    public const LIFETIME = '+1 hour';

    /**
     * @param string|null $network The requesting client network key (ClientNetwork), stored hashed.
     *
     * @return string The token for the link.
     */
    public function issue(int $userId, ?string $network, DateTimeImmutable $now): string;

    /**
     * The user a valid (known, unused, unexpired) token belongs to, without using it.
     */
    public function peek(#[SensitiveParameter] string $token, DateTimeImmutable $now): ?int;

    /**
     * Sets the new password hash with the token in one transaction: the token and every other open reset token of
     * the user are used up, all remember-me tokens are deleted and the session epoch is raised.
     *
     * @return int|null The user's ID, or null when the token is unknown, used or expired.
     */
    public function reset(
        #[SensitiveParameter]
        string $token,
        string $passwordHash,
        DateTimeImmutable $now,
    ): ?int;
}
