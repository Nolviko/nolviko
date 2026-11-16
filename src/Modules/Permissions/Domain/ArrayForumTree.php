<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

use InvalidArgumentException;

/**
 * A ForumTree from a `forum ID => parent ID (null for a root)` map. Cycles and unknown parents are refused on
 * construction (`forum_tree_invalid:<id>`), so ancestry() always ends.
 */
final class ArrayForumTree implements ForumTree
{
    /** @var array<int, list<int>> */
    private readonly array $ancestry;

    /**
     * @param array<int, int|null> $parents
     */
    public function __construct(array $parents)
    {
        $ancestry = [];

        foreach (array_keys($parents) as $forumId) {
            $chain = [];

            for ($current = $forumId; $current !== null; $current = $parents[$current] ?? null) {
                if (in_array($current, $chain, true) || !array_key_exists($current, $parents)) {
                    throw new InvalidArgumentException('forum_tree_invalid:' . $forumId);
                }

                $chain[] = $current;
            }

            $ancestry[$forumId] = $chain;
        }

        $this->ancestry = $ancestry;
    }

    public function ancestry(int $forumId): array
    {
        return $this->ancestry[$forumId] ?? [];
    }

    public function forumIds(): array
    {
        return array_keys($this->ancestry);
    }
}
