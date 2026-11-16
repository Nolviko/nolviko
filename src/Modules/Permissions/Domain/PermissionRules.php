<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * The stored rules that apply to one user, one key and one forum, grouped by ADR-011 level. Values are the
 * stored text (`1`, `0`, `never`, or the typed encoding); a missing rule (inherit) is simply absent.
 *
 * Forum chains are ordered from the forum asked about up to its root: element 0 is the forum itself,
 * element 1 its parent, and so on. Each element lists the rule values on that forum (at most one for the user
 * levels, one per group for the group levels).
 */
final class PermissionRules
{
    /**
     * @param list<list<string>> $userForum L1: user overrides per forum in the chain.
     * @param list<string> $user L2: the user's global override (zero or one value).
     * @param list<list<string>> $groupForum L3: the user's groups' rules per forum in the chain.
     * @param list<string> $group L4: the user's groups' global rules.
     */
    public function __construct(
        public readonly array $userForum = [],
        public readonly array $user = [],
        public readonly array $groupForum = [],
        public readonly array $group = [],
    ) {
    }
}
