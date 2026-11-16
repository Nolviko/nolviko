<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * Every stored rule that can apply to one subject (its groups and, if logged in, the user), loaded at once so
 * that resolving any number of keys and forums costs no further queries.
 *
 * Shapes: `group[key][groupId]`, `groupForum[forumId][key][groupId]`, `user[key]`, `userForum[forumId][key]`;
 * each leaf is the stored rule text.
 */
final class RuleSnapshot
{
    /**
     * @param array<string, array<int, string>> $group
     * @param array<int, array<string, array<int, string>>> $groupForum
     * @param array<string, string> $user
     * @param array<int, array<string, string>> $userForum
     */
    public function __construct(
        public readonly array $group = [],
        public readonly array $groupForum = [],
        public readonly array $user = [],
        public readonly array $userForum = [],
        public readonly array $moderatorSources = [],
    ) {
    }
}
