<?php

declare(strict_types=1);

namespace Nolviko\Modules\Groups\Domain;

/**
 * Groups and memberships.
 *
 * Primary and secondary groups: a member has any number of groups and exactly one of them is primary. The
 * primary group decides how the user is shown (title, colour, rank) and is the default; for permissions all
 * groups count alike (combined under ADR-011, M02.08). The first membership a user gets becomes primary; the
 * primary membership cannot be removed until another group is made primary. Deleting a custom group removes
 * its memberships; users for whom it was primary fall back to Registered.
 */
interface UsergroupRepository
{
    public const SLUG_PATTERN = '/^[a-z][a-z0-9-]{1,63}$/D';

    /**
     * @return list<Usergroup> Ordered by ID (built-ins first).
     */
    public function all(): array;

    public function find(int $id): ?Usergroup;

    public function findBySlug(string $slug): ?Usergroup;

    /**
     * @throws GroupRuleViolation `group_slug_invalid` or `group_slug_taken`.
     */
    public function create(string $slug, string $name): Usergroup;

    /**
     * @throws GroupRuleViolation `group_builtin_protected` or `group_not_found`.
     */
    public function delete(int $id): void;

    /**
     * Adds a membership (idempotent); the user's first membership becomes primary, or this one if $primary.
     *
     * @throws GroupRuleViolation `group_guests_no_members` or `group_not_found`.
     */
    public function addMember(int $userId, int $groupId, bool $primary = false): void;

    /**
     * @throws GroupRuleViolation `group_primary_required` when this is the primary membership.
     */
    public function removeMember(int $userId, int $groupId): void;

    /**
     * @throws GroupRuleViolation `group_not_member`.
     */
    public function setPrimary(int $userId, int $groupId): void;

    /**
     * @return list<int> The user's group IDs, primary first, then by ID.
     */
    public function groupIdsOf(int $userId): array;

    public function primaryGroupOf(int $userId): ?int;
}
