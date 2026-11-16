<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Closure;
use Nolviko\Core\Session\Session;
use Nolviko\Modules\Groups\Domain\UsergroupRepository;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Users\Domain\SecondFactors;

/**
 * Session side of the second sign-in step (2026-11-15). After the password a member with an enabled method is
 * not signed in yet: the session holds a pending sign-in for five minutes and five codes. A member whose group
 * requires a method and who has none is signed in, but the setup guard keeps them on the setup page.
 */
final class SecondFactorLogin
{
    public const MAX_TRIES = 5;

    public const PENDING_SECONDS = 300;

    public const REQUIRED_PERMISSION = 'user.second_factor.required';

    private const PENDING_KEY = '_second_factor_pending';

    public const SETUP_KEY = '_second_factor_setup';

    private readonly Closure $clock;

    /** @param (Closure(): int)|null $clock Unix time. */
    public function __construct(
        private readonly Session $session,
        private readonly SecondFactors $factors,
        private readonly UsergroupRepository $groups,
        private readonly PermissionResolver $resolver,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function factors(): SecondFactors
    {
        return $this->factors;
    }

    /** True when the member must pass the second step before being signed in. */
    public function needsCode(int $userId): bool
    {
        return $this->factors->active($userId) !== [];
    }

    public function begin(int $userId, bool $remember): void
    {
        $this->session->set(self::PENDING_KEY, [
            'user' => $userId,
            'remember' => $remember,
            'until' => ($this->clock)() + self::PENDING_SECONDS,
            'tries' => 0,
        ]);
    }

    /** @return array{user: int, remember: bool, tries: int}|null */
    public function pending(): ?array
    {
        $pending = $this->session->get(self::PENDING_KEY);
        if (
            !is_array($pending) || !is_int($pending['user'] ?? null) || !is_int($pending['until'] ?? null)
            || !is_int($pending['tries'] ?? null) || $pending['until'] < ($this->clock)()
            || $pending['tries'] >= self::MAX_TRIES
        ) {
            $this->session->remove(self::PENDING_KEY);

            return null;
        }

        return ['user' => $pending['user'], 'remember' => ($pending['remember'] ?? false) === true,
            'tries' => $pending['tries']];
    }

    /** Counts a wrong code; returns the tries left (0: the pending sign-in is gone). */
    public function failed(): int
    {
        $pending = $this->session->get(self::PENDING_KEY);
        if (!is_array($pending) || !is_int($pending['tries'] ?? null)) {
            return 0;
        }

        $pending['tries'] += 1;
        if ($pending['tries'] >= self::MAX_TRIES) {
            $this->session->remove(self::PENDING_KEY);

            return 0;
        }
        $this->session->set(self::PENDING_KEY, $pending);

        return self::MAX_TRIES - $pending['tries'];
    }

    public function clear(): void
    {
        $this->session->remove(self::PENDING_KEY);
    }

    /** After a completed sign-in: remember that this member still has to set a method up, when a group says so. */
    public function signedIn(int $userId): void
    {
        $this->session->remove(self::SETUP_KEY);
        if (
            $this->factors->available() && $this->factors->active($userId) === []
            && $this->resolver->user($userId, $this->groups->groupIdsOf($userId))->allows(self::REQUIRED_PERMISSION)
        ) {
            $this->session->set(self::SETUP_KEY, $userId);
        }
    }

    /** The member the setup guard holds, or null; a member who has set a method up meanwhile is released. */
    public function setupRequired(?int $userId): ?int
    {
        $held = $this->session->get(self::SETUP_KEY);
        if (!is_int($held)) {
            return null;
        }
        if ($held !== $userId || !$this->factors->available() || $this->factors->active($held) !== []) {
            $this->session->remove(self::SETUP_KEY);

            return null;
        }

        return $held;
    }
}
