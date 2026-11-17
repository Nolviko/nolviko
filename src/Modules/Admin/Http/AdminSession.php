<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Session\Session;
use Nolviko\Modules\Users\Http\AuthSession;

/**
 * Administrator password confirmation (M02.15), owner decision 2026-11-18: ordinary admin work needs no password
 * again (isFresh() is always true; the admin permission and CSRF still guard every action). Only actions that can
 * break the site, such as a core update, ask for the password (and second factor) in a popup at the moment they are
 * started (isConfirmed()); that confirmation then slides while the action keeps running and lapses after
 * FRESH_SECONDS without use. Signing in never confirms. Kept in the session, which is regenerated at login and
 * invalidated at logout.
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

    /** Ordinary admin actions: no password prompt (owner decision 2026-11-18). */
    public function isFresh(): bool
    {
        return true;
    }

    /** Actions that can break the site: confirmed in the popup within the last FRESH_SECONDS of use. */
    public function isConfirmed(): bool
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
