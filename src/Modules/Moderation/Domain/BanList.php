<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use DateTimeImmutable;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Users\Domain\AccessBans;

/**
 * The stored IP and email bans (M04.09a). Writes are one transaction with their audit entry and outbox event.
 */
interface BanList extends AccessBans
{
    /**
     * @return int The ban ID.
     */
    public function add(
        BanEntry $entry,
        string $reason,
        ?DateTimeImmutable $expiresAt,
        int $actorId,
        DateTimeImmutable $at,
    ): int;

    /**
     * @throws ThreadRuleViolation `ban_not_found` when the ban does not exist or is no longer active.
     */
    public function lift(int $banId, int $actorId, DateTimeImmutable $at): void;

    /**
     * Active bans, newest first.
     *
     * @return list<array{id: int, entry: BanEntry, reason: string, expires_at: ?DateTimeImmutable}>
     */
    public function active(DateTimeImmutable $now, int $limit): array;
}
