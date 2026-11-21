<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

use Nolviko\Modules\Groups\Domain\BuiltInGroup;

/**
 * Builds SubjectPermissions (ADR-011): guests resolve through the `guests` group only, logged-in users through
 * all their groups plus their overrides. Subjects are memoized per request, so one subject costs one rule
 * load (at most four queries for a user, two for a guest) however many checks follow.
 */
final class PermissionResolver
{
    /** @var array<string, SubjectPermissions> */
    private array $subjects = [];

    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly PermissionRuleStore $store,
        private readonly ForumTree $forums,
        /** @var (Closure(): mixed)|null The session's ForumLocks::SESSION_KEY entry; null: nothing unlocked. */
        private readonly ?Closure $unlocked = null,
    ) {
    }

    public function guest(): SubjectPermissions
    {
        return $this->subject([BuiltInGroup::Guests->value], null);
    }

    /**
     * @param list<int> $groupIds The user's groups (UsergroupRepository::groupIdsOf()).
     */
    public function user(int $userId, array $groupIds): SubjectPermissions
    {
        return $this->subject($groupIds, $userId);
    }

    /**
     * @param list<int> $groupIds
     */
    private function subject(array $groupIds, ?int $userId): SubjectPermissions
    {
        sort($groupIds);
        $memoKey = ($userId ?? 'guest') . ':' . implode(',', $groupIds);

        return $this->subjects[$memoKey] ??= new SubjectPermissions(
            $this->catalog,
            $this->forums,
            $this->store->load($groupIds, $userId),
            $groupIds,
            $userId,
            $this->forums instanceof ProtectedForumTree ? new ForumLocks(
                $this->forums->protectedForums(...),
                $this->unlocked ?? static fn(): array => [],
            ) : null,
        );
    }
}
