<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use DateTimeImmutable;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Users\Domain\AccountBans;

/**
 * Bans and warnings (M04.09). Every write is one transaction with its audit entry and outbox event.
 */
interface UserSanctions extends AccountBans
{
    /**
     * @return int The ban ID.
     */
    public function ban(
        int $userId,
        int $actorId,
        string $reason,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): int;

    /**
     * Lifts every active ban of the user.
     *
     * @throws ThreadRuleViolation `user_not_banned`.
     */
    public function lift(int $userId, int $actorId, ?string $reason, DateTimeImmutable $at): void;

    /**
     * @return int The warning ID.
     */
    public function warn(
        int $userId,
        int $actorId,
        int $points,
        string $reason,
        ?DateTimeImmutable $expiresAt,
        DateTimeImmutable $at,
    ): int;

    /**
     * Points of the warnings that have not expired.
     */
    public function activePoints(int $userId, DateTimeImmutable $now): int;
}
