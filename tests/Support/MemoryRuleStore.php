<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\PermissionRuleStore;
use Nolviko\Modules\Permissions\Domain\RuleSnapshot;

/**
 * In-memory PermissionRuleStore (the DBAL one is integration-tested); counts loads to prove memoization.
 */
final class MemoryRuleStore implements PermissionRuleStore
{
    public int $loads = 0;

    /** @var array<string, array<int, string>> */
    private array $group = [];

    /** @var array<int, array<string, array<int, string>>> */
    private array $groupForum = [];

    /** @var array<int, array<string, string>> */
    private array $user = [];

    /** @var array<int, array<int, array<string, string>>> */
    private array $userForum = [];

    public function __construct(
        private readonly PermissionCatalog $catalog,
    ) {
    }

    public function load(array $groupIds, ?int $userId): RuleSnapshot
    {
        ++$this->loads;
        $inGroups = static fn(array $byGroup): array => array_intersect_key($byGroup, array_flip($groupIds));
        $groupForum = [];

        foreach ($this->groupForum as $forumId => $keys) {
            $groupForum[$forumId] = array_filter(array_map($inGroups, $keys));
        }

        return new RuleSnapshot(
            array_filter(array_map($inGroups, $this->group)),
            $groupForum,
            $userId === null ? [] : ($this->user[$userId] ?? []),
            $userId === null ? [] : ($this->userForum[$userId] ?? []),
        );
    }

    public function setGroupRule(int $groupId, string $key, mixed $value, ?int $forumId = null): void
    {
        $encoded = $this->encode($key, $value);

        if ($forumId === null) {
            $this->group[$key][$groupId] = $encoded;
        } else {
            $this->groupForum[$forumId][$key][$groupId] = $encoded;
        }
    }

    public function setUserRule(int $userId, string $key, mixed $value, ?int $forumId = null): void
    {
        $encoded = $this->encode($key, $value);

        if ($forumId === null) {
            $this->user[$userId][$key] = $encoded;
        } else {
            $this->userForum[$userId][$forumId][$key] = $encoded;
        }
    }

    private function encode(string $key, mixed $value): string
    {
        $definition = $this->catalog->find($key) ?? throw new PermissionError('permission_unknown:' . $key);

        return $definition->encodeRule($value);
    }
}
