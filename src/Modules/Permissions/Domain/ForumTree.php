<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * Forum parentage for permission inheritance (ADR-011 L1/L3). The forums module provides the stored tree
 * (M03); until then ArrayForumTree holds it.
 */
interface ForumTree
{
    /**
     * @return list<int> The forum itself, then its parent, up to the root. Empty for an unknown forum.
     */
    public function ancestry(int $forumId): array;

    /**
     * @return list<int> Every forum ID, in display order.
     */
    public function forumIds(): array;
}
