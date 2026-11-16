<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * Stored permission rules (ADR-011 tables). Writes validate the value against the catalog definition; a
 * null value removes the rule (inherit).
 */
interface PermissionRuleStore
{
    /**
     * All rules for these groups and, when $userId is given, that user's overrides.
     *
     * @param list<int> $groupIds
     */
    public function load(array $groupIds, ?int $userId): RuleSnapshot;

    /**
     * @throws PermissionError `permission_value_invalid:<key>` or `permission_unknown:<key>`.
     */
    public function setGroupRule(int $groupId, string $key, mixed $value, ?int $forumId = null): void;

    /**
     * @throws PermissionError `permission_value_invalid:<key>` or `permission_unknown:<key>`.
     */
    public function setUserRule(int $userId, string $key, mixed $value, ?int $forumId = null): void;
}
