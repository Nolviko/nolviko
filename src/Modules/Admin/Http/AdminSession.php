<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Session\Session;
use Nolviko\Modules\Users\Http\AuthSession;

/**
 * Recent authentication of an administrator (M02.15): sensitive admin actions need the password (and second factor)
 * confirmed recently. Owner decision 2026-11-17 (phpBB/MyBB-like, no prompt on every page): the sign-in itself
 * confirms, and the confirmation slides while the administrator stays active; it lapses after FRESH_SECONDS without
 * admin activity, or when the member came back through a remember-me cookie. Kept in the session, which is
 * regenerated at login and invalidated at logout, so it never outlives the sign-in it belongs to.
 */
final class AdminSession
{
    public const FRESH_SECONDS = 3600;

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
        if ($this->remainingSeconds() <= 0) {
            return false;
        }
        // Activity keeps the confirmation alive (idle timeout, not an absolute one).
        $this->confirm();

        return true;
    }

    /** Seconds left in the confirmation window; 0 when it has not been opened or has run out. */
    public function remainingSeconds(): int
    {
        $at = $this->session->get(self::KEY);

        return is_int($at) ? max(0, self::FRESH_SECONDS - (($this->clock)()->getTimestamp() - $at)) : 0;
    }
}
