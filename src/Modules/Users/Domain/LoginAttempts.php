<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use DateTimeImmutable;

/**
 * Storage of login attempts for LoginThrottle (`login_attempts`).
 */
interface LoginAttempts
{
    /**
     * @return list<DateTimeImmutable> Failure times for the normalized identifier since $since, oldest first.
     */
    public function failuresForAccount(string $account, DateTimeImmutable $since): array;

    /**
     * @param string $network Binary key from LoginThrottle::network().
     *
     * @return list<DateTimeImmutable> Failure times from that network since $since, oldest first.
     */
    public function failuresForNetwork(string $network, DateTimeImmutable $since): array;

    public function record(
        string $account,
        string $network,
        bool $succeeded,
        ?int $userId,
        DateTimeImmutable $at,
    ): void;

    /**
     * Removes an account's failures (successful login; an administrator clearing a block).
     */
    public function clearAccount(string $account): void;

    /**
     * Removes a network's failures (an administrator clearing an IP block).
     */
    public function clearNetwork(string $network): void;

    public function prune(DateTimeImmutable $before): void;
}
