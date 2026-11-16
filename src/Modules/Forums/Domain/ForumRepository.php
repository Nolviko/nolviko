<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Domain;

use Nolviko\Modules\Permissions\Domain\ForumScope;

/**
 * The forum tree (ADR-027: adjacency list with stored depth). Every structural change bumps the permission rules
 * version, because inherited forum permissions (ADR-011) depend on the tree.
 */
interface ForumRepository
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/D';

    public const MAX_SLUG_LENGTH = 100;

    /**
     * Also creates the forum's statistics row (M03.04).
     *
     * @throws ForumRuleViolation `forum_slug_invalid`, `forum_slug_taken`, `forum_parent_not_found` or
     *     `forum_too_deep`.
     */
    public function create(
        ForumType $type,
        string $title,
        string $slug,
        ?int $parentId = null,
        int $sortOrder = 0,
    ): Forum;

    public function find(int $id): ?Forum;

    /**
     * @return list<Forum> Every forum, parents before children, siblings by sort order then ID.
     */
    public function all(): array;

    /**
     * A page of the forums in the scope, in tree order, filtered in SQL before the page is cut (M02.10).
     *
     * @return list<Forum>
     */
    public function listIn(ForumScope $scope, int $limit, int $offset = 0): array;

    /**
     * How many forums are in the scope, counted in SQL.
     */
    public function countIn(ForumScope $scope): int;

    /**
     * Moves a forum (with its subtree) under another parent, or to the root with null.
     *
     * @throws ForumRuleViolation `forum_not_found`, `forum_parent_not_found`, `forum_move_into_descendant`,
     *     `forum_too_deep`.
     */
    public function move(int $id, ?int $parentId, int $sortOrder = 0): void;

    /**
     * @throws ForumRuleViolation `forum_not_found`.
     */
    public function setLocked(int $id, bool $locked): void;

    /**
     * Title, address name (slug) and plain-text description (null or '' clears it).
     *
     * @throws ForumRuleViolation `forum_title_invalid`, `forum_slug_invalid`, `forum_slug_taken`,
     *     `forum_description_invalid` or `forum_not_found`.
     */
    public function update(
        int $id,
        string $title,
        string $slug,
        ?string $description,
        ?bool $questions = null,
    ): void;

    /**
     * @throws ForumRuleViolation `forum_not_found`, `forum_has_children` or `forum_has_threads` (FK RESTRICT).
     */
    public function delete(int $id): void;
}
