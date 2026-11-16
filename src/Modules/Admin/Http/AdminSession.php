<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Session\Session;
use Nolviko\Modules\Users\Http\AuthSession;

/**
 * Recent re-authentication of an administrator (M02.15): sensitive admin actions require the password to have
 * been confirmed within FRESH_SECONDS. Kept in the session, which is regenerated at login and invalidated at
 * logout, so freshness never outlives the sign-in it belongs to.
 */
final class AdminSession
{
    public const FRESH_SECONDS = 900;

    private const KEY = AuthSession::ELEVATION_KEY;

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly Session $session,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    public function confirm(): void
    {
        $this->session->set(self::KEY, ($this->clock)()->getTimestamp());
    }

    public function isFresh(): bool
    {
        return $this->remainingSeconds() > 0;
    }

    /** Seconds left in the confirmation window; 0 when it has not been opened or has run out. */
    public function remainingSeconds(): int
    {
        $at = $this->session->get(self::KEY);

        return is_int($at) ? max(0, self::FRESH_SECONDS - (($this->clock)()->getTimestamp() - $at)) : 0;
    }
}
