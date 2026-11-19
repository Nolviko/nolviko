<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

/**
 * A per-user counter stored with each login (M02.05). Raising it ends every session that was started earlier,
 * on any device, without having to find those sessions.
 */
interface SessionEpochs
{
    public function revokeSessionsAndRememberTokens(int $userId): void;

    /**
     * @return int|null The current epoch, or null when the user does not exist.
     */
    public function current(int $userId): ?int;
}
