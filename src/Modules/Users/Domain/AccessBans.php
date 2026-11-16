<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use DateTimeImmutable;

/**
 * IP, IP range, email and email-domain bans (M04.09a), checked at registration, at login and on every request
 * of a signed-in user (which covers posting).
 */
interface AccessBans
{
    /**
     * Whether an active ban matches the client address or the email address (null skips that part).
     */
    public function blocks(?string $ip, ?string $email, DateTimeImmutable $now): bool;

    /**
     * Whether an active ban matches the client address or the stored email of the user.
     */
    public function blocksUser(int $userId, ?string $ip, DateTimeImmutable $now): bool;
}
