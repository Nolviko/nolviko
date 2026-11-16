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

    /** @var array<string, PermissionTrace> */
    private array $memo = [];

    private ?string $cacheIdentity = null;

    /**
     * @param list<int> $groupIds
     */
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly ForumTree $forums,
        private readonly RuleSnapshot $rules,
        public readonly array $groupIds,
        public readonly ?int $userId,
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

    public function explain(string $key, ?int $forumId = null): PermissionTrace
    {
        return $this->memo[$key . '@' . ($forumId ?? '')] ??= $this->resolve($key, $forumId);
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

    public function cacheIdentity(): string
    {
        if ($this->cacheIdentity !== null) {
            return $this->cacheIdentity;
        }
        $tree = [];
        foreach ($this->forums->forumIds() as $id) {
            $tree[$id] = $this->forums->ancestry($id);
        }
        return $this->cacheIdentity = hash('sha256', serialize([$this->userId, $this->groupIds, $this->rules, $tree]));
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
