<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use DateTimeImmutable;

/**
 * Whether an account is banned right now (M04.09): used at login and for every request of a signed-in user.
 */
interface AccountBans
{
    /**
     * @return DateTimeImmutable|false|null False when not banned, null for a permanent ban, otherwise its end.
     */
    public function activeBanUntil(int $userId, DateTimeImmutable $now): DateTimeImmutable|false|null;
}
