<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * A forum tree that also knows which forums have a password (ADR-041). PermissionResolver gates every subject
 * with it, so each read surface that asks the permission layer hides locked content.
 */
interface ProtectedForumTree extends ForumTree
{
    /**
     * @return array<int, string> Forum ID => ForumLocks::fingerprint() of its password hash.
     */
    public function protectedForums(): array;
}
