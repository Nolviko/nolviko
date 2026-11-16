<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Modules\Groups\Domain\UsergroupRepository;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Users\Domain\AccessBans;
use Nolviko\Modules\Users\Domain\AccountBans;
use Symfony\Component\HttpFoundation\Request;

/**
 * The permission subject of a request: the logged-in user with their groups, or the guest. Resolved once per
 * request and shared by every check of that request. A banned user keeps their session but gets the guest's
 * permissions (M04.09), so a ban takes effect on their next request without touching sessions. The same holds
 * for a user whose email, email domain or current client address is banned (M04.09a).
 */
final class RequestSubject
{
    private ?SubjectPermissions $subject = null;

    public function __construct(
        private readonly AuthSession $auth,
        private readonly UsergroupRepository $groups,
        private readonly PermissionResolver $resolver,
        private readonly ?AccountBans $bans = null,
        private readonly ?AccessBans $access = null,
    ) {
    }

    /**
     * The logged-in user's ID without resolving permissions (no database work for guests).
     */
    public function userId(Request $request): ?int
    {
        return $this->auth->userId($request);
    }

    public function of(Request $request): SubjectPermissions
    {
        if ($this->subject !== null) {
            return $this->subject;
        }

        $userId = $this->auth->userId($request);

        if ($userId === null || $this->banned($userId, $request)) {
            return $this->subject = $this->resolver->guest();
        }

        return $this->subject = $this->resolver->user($userId, $this->groups->groupIdsOf($userId));
    }

    private function banned(int $userId, Request $request): bool
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $this->bans !== null && $this->bans->activeBanUntil($userId, $now) !== false
            || $this->access !== null && $this->access->blocksUser($userId, $request->getClientIp(), $now);
    }
}
