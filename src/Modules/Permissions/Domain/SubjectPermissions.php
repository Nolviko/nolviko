<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * The permissions of one subject (a guest, or a user with their groups) for one request: rules come from one
 * RuleSnapshot, and every `(key, forum)` decision is computed once and memoized.
 *
 * Unknown keys: the catalog throws in development; in production the decision is a deny with reason
 * `unknown`, so a typo can never open access.
 */
final class SubjectPermissions
{
    public const UNKNOWN = 'unknown';

    /** Reason of a decision denied by the forum password gate (ADR-041). */
    public const PASSWORD = 'forum_password';

    private const FORUM_VIEW = 'forum.view';

    private const ADMIN_ACCESS = 'admin.access';

    /** @var array<string, PermissionTrace> */
    private array $memo = [];

    /** @var array<int, bool> */
    private array $locked = [];

    /** @var list<int>|null */
    private ?array $lockedList = null;

    private ?string $cacheIdentity = null;

    /**
     * @param list<int> $groupIds
     * @param ForumLocks|null $locks Null: no password gate (tests, trees without passwords).
     */
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly ForumTree $forums,
        private readonly RuleSnapshot $rules,
        public readonly array $groupIds,
        public readonly ?int $userId,
        private readonly ?ForumLocks $locks = null,
    ) {
    }

    /**
     * True when the user has personal rules, so results must not be shared with others in the same groups.
     */
    public function hasUserOverrides(): bool
    {
        return $this->rules->user !== [] || $this->rules->userForum !== [];
    }

    public function allows(string $key, ?int $forumId = null): bool
    {
        return $this->explain($key, $forumId)->decision->allowed();
    }

    public function value(string $key, ?int $forumId = null): bool|int|string
    {
        return $this->explain($key, $forumId)->decision->value;
    }

    /**
     * The decision with the forum password gate (ADR-041): in a forum behind a password the visitor has not
     * entered, every allowed forum permission except seeing the forum's name is denied with reason PASSWORD.
     * Administrators and the forum's moderators are never gated.
     */
    public function explain(string $key, ?int $forumId = null): PermissionTrace
    {
        $trace = $this->raw($key, $forumId);

        if (
            $forumId === null || $this->locks === null || $key === self::FORUM_VIEW
            || !$trace->decision->allowed() || !$this->lockedFor($forumId)
        ) {
            return $trace;
        }

        return new PermissionTrace($key, $forumId, new PermissionDecision(false, self::PASSWORD), $trace->rules);
    }

    /**
     * @return list<int> The forums in which `$key` is allowed (visibility sets, M02.10).
     */
    public function forumsAllowing(string $key): array
    {
        return array_values(array_filter(
            $this->forums->forumIds(),
            fn(int $forumId): bool => $this->allows($key, $forumId),
        ));
    }

    /**
     * Like forumsAllowing() but without the password gate: only for sets that are shared between visitors of the
     * same groups (ForumVisibility caches them and removes lockedForums() per visitor afterwards).
     *
     * @return list<int>
     */
    public function forumsAllowingUngated(string $key): array
    {
        return array_values(array_filter(
            $this->forums->forumIds(),
            fn(int $forumId): bool => $this->raw($key, $forumId)->decision->allowed(),
        ));
    }

    /** Whether the forum is behind a password this visitor has not entered and may not bypass (ADR-041). */
    public function lockedFor(int $forumId): bool
    {
        if ($this->locks === null) {
            return false;
        }

        return $this->locked[$forumId] ??= $this->locks->blocking($this->forums->ancestry($forumId)) !== null
            && !$this->bypasses($forumId);
    }

    /** The forum whose password opens $forumId for this visitor, or null when nothing blocks it. */
    public function lockingForum(int $forumId): ?int
    {
        return $this->lockedFor($forumId) ? $this->locks?->blocking($this->forums->ancestry($forumId)) : null;
    }

    /** Whether any forum has a password at all (ADR-041); false without a password gate. */
    public function anyProtected(): bool
    {
        return $this->locks?->any() ?? false;
    }

    /**
     * @return list<int> Every forum locked for this visitor (empty when no forum has a password).
     */
    public function lockedForums(): array
    {
        if ($this->locks === null || !$this->locks->any()) {
            return [];
        }

        return $this->lockedList ??= array_values(array_filter(
            $this->forums->forumIds(),
            fn(int $forumId): bool => $this->lockedFor($forumId),
        ));
    }

    public function cacheIdentity(): string
    {
        if ($this->cacheIdentity !== null) {
            return $this->cacheIdentity;
        }
        $tree = [];
        foreach ($this->forums->forumIds() as $id) {
            $tree[$id] = $this->forums->ancestry($id);
        }
        return $this->cacheIdentity = hash('sha256', serialize([$this->userId, $this->groupIds, $this->rules, $tree,
            $this->locks?->identity() ?? '']));
    }

    private function raw(string $key, ?int $forumId): PermissionTrace
    {
        return $this->memo[$key . '@' . ($forumId ?? '')] ??= $this->resolve($key, $forumId);
    }

    /** Administrators, and members with a local moderator capability in the forum, are never gated. */
    private function bypasses(int $forumId): bool
    {
        if ($this->userId === null) {
            return false;
        }

        if ($this->raw(self::ADMIN_ACCESS, null)->decision->allowed()) {
            return true;
        }

        foreach (ForumModeratorCapabilities::KEYS as $key) {
            if ($this->raw($key, $forumId)->decision->allowed()) {
                return true;
            }
        }

        return false;
    }

    private function resolve(string $key, ?int $forumId): PermissionTrace
    {
        $definition = $this->catalog->find($key);

        if ($definition === null) {
            return new PermissionTrace($key, $forumId, new PermissionDecision(false, self::UNKNOWN), []);
        }

        $chain = $forumId === null ? [] : $this->forums->ancestry($forumId);
        $consulted = [];
        $userForum = [];
        $groupForum = [];

        foreach ($chain as $forum) {
            $userValue = $this->rules->userForum[$forum][$key] ?? null;
            $userForum[] = $userValue === null ? [] : [$userValue];
            $groupValues = $this->rules->groupForum[$forum][$key] ?? [];
            $groupForum[] = array_values($groupValues);

            if ($userValue !== null) {
                $consulted[] = self::entry(
                    'user_forum',
                    $forum,
                    isset($this->rules->moderatorSources[$forum][$key]['user']) ? 'forum_moderator:user' : 'user',
                    $userValue
                );
            }

            foreach ($groupValues as $groupId => $value) {
                $consulted[] = self::entry(
                    'group_forum',
                    $forum,
                    isset($this->rules->moderatorSources[$forum][$key]['group:' . $groupId])
                    ? 'forum_moderator:group:' . $groupId : 'group:' . $groupId,
                    $value
                );
            }
        }

        $user = isset($this->rules->user[$key]) ? [$this->rules->user[$key]] : [];

        foreach ($user as $value) {
            $consulted[] = self::entry('user', null, 'user', $value);
        }

        foreach ($this->rules->group[$key] ?? [] as $groupId => $value) {
            $consulted[] = self::entry('group', null, 'group:' . $groupId, $value);
        }

        $decision = Precedence::decide(
            $definition,
            new PermissionRules($userForum, $user, $groupForum, array_values($this->rules->group[$key] ?? [])),
        );

        return new PermissionTrace($key, $forumId, $decision, $consulted);
    }

    /**
     * @return array{level: string, forum_id: int|null, source: string, value: string}
     */
    private static function entry(string $level, ?int $forumId, string $source, string $value): array
    {
        return ['level' => $level, 'forum_id' => $forumId, 'source' => $source, 'value' => $value];
    }
}
