<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Modules\Forums\Domain\Forum;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumRuleViolation;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Infrastructure\ScopeSql;

/**
 * ForumRepository on DBAL. Tree checks run on the whole tree loaded in one query (ADR-027) inside the same
 * transaction as the write, with the rows locked, so two concurrent moves cannot create a cycle.
 */
final class DbalForumRepository implements ForumRepository
{
    /** Default maximum depth: root = 0, so 8 means nine levels. */
    public const DEFAULT_MAX_DEPTH = 8;

    private const COLUMNS = 'id, parent_id, type, title, slug, sort_order, depth, is_locked, description, '
        . 'is_questions, link_url, link_counts, link_clicks, counts_posts, password_hash, guest_posting';

    public const MAX_TITLE_LENGTH = 200;

    public const MAX_DESCRIPTION_LENGTH = 2000;

    public function __construct(
        private readonly Connection $connection,
        private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
    ) {
    }

    public function create(
        ForumType $type,
        string $title,
        string $slug,
        ?int $parentId = null,
        int $sortOrder = 0,
    ): Forum {
        if (strlen($slug) > self::MAX_SLUG_LENGTH || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new ForumRuleViolation('forum_slug_invalid');
        }

        $id = $this->connection->transactional(function (Connection $connection) use (
            $type,
            $title,
            $slug,
            $parentId,
            $sortOrder,
        ): int {
            $depth = $parentId === null ? 0 : $this->depthUnder($this->lockedTree($connection), $parentId);
            $now = self::now();

            VersionCounter::bump($connection, VersionCounter::PERMISSION_RULES);

            try {
                $connection->insert('forums', [
                    'parent_id' => $parentId,
                    'type' => $type->value,
                    'title' => $title,
                    'slug' => $slug,
                    'sort_order' => $sortOrder,
                    'depth' => $depth,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new ForumRuleViolation('forum_slug_taken');
            }

            $id = (int) $connection->lastInsertId();
            $connection->insert('forum_statistics', ['forum_id' => $id, 'updated_at' => $now]);

            return $id;
        });

        return $this->find($id) ?? throw new ForumRuleViolation('forum_not_found');
    }

    public function find(int $id): ?Forum
    {
        $row = $this->connection->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM forums WHERE id = ?', [$id]);

        return $row === false ? null : self::hydrate($row);
    }

    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT ' . self::COLUMNS . ' FROM forums');
        $children = [];

        foreach ($rows as $row) {
            $children[$row['parent_id'] === null ? 0 : (int) $row['parent_id']][] = self::hydrate($row);
        }

        foreach ($children as &$siblings) {
            usort(
                $siblings,
                static fn(Forum $a, Forum $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id],
            );
        }

        unset($siblings);

        return self::flatten($children, 0);
    }

    public function move(int $id, ?int $parentId, int $sortOrder = 0): void
    {
        $this->connection->transactional(function (Connection $connection) use ($id, $parentId, $sortOrder): void {
            $tree = $this->lockedTree($connection);

            if (!array_key_exists($id, $tree)) {
                throw new ForumRuleViolation('forum_not_found');
            }

            $subtree = self::subtree($tree, $id);

            if ($parentId !== null && in_array($parentId, $subtree, true)) {
                throw new ForumRuleViolation('forum_move_into_descendant');
            }

            $depth = $parentId === null ? 0 : $this->depthUnder($tree, $parentId);
            $height = max(array_map(static fn(int $node): int => self::depthOf($tree, $node), $subtree))
                - self::depthOf($tree, $id);

            if ($depth + $height > $this->maxDepth) {
                throw new ForumRuleViolation('forum_too_deep');
            }

            VersionCounter::bump($connection, VersionCounter::PERMISSION_RULES);
            $connection->update('forums', [
                'parent_id' => $parentId,
                'sort_order' => $sortOrder,
                'updated_at' => self::now(),
            ], ['id' => $id]);
            $tree[$id] = $parentId;

            foreach ($subtree as $node) {
                $connection->update('forums', ['depth' => self::depthOf($tree, $node)], ['id' => $node]);
            }
        });
    }

    public function setLocked(int $id, bool $locked): void
    {
        $exists = $this->connection->fetchOne('SELECT 1 FROM forums WHERE id = ?', [$id]);

        if ($exists === false) {
            throw new ForumRuleViolation('forum_not_found');
        }

        $this->connection->update(
            'forums',
            ['is_locked' => $locked ? 1 : 0, 'updated_at' => self::now()],
            ['id' => $id],
        );
    }

    public function update(
        int $id,
        string $title,
        string $slug,
        ?string $description,
        ?bool $questions = null,
    ): void {
        $title = trim($title);
        $description = $description === null ? null : trim($description);

        if ($title === '' || !mb_check_encoding($title, 'UTF-8') || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new ForumRuleViolation('forum_title_invalid');
        }

        if (strlen($slug) > self::MAX_SLUG_LENGTH || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw new ForumRuleViolation('forum_slug_invalid');
        }

        if (
            $description !== null
            && (!mb_check_encoding($description, 'UTF-8') || mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH)
        ) {
            throw new ForumRuleViolation('forum_description_invalid');
        }

        if ($this->connection->fetchOne('SELECT 1 FROM forums WHERE id = ?', [$id]) === false) {
            throw new ForumRuleViolation('forum_not_found');
        }

        try {
            $this->connection->update('forums', [
                'title' => $title,
                'slug' => $slug,
                'description' => $description === '' ? null : $description,
                'updated_at' => self::now(),
            ] + ($questions === null ? [] : ['is_questions' => $questions ? 1 : 0]), ['id' => $id]);
        } catch (UniqueConstraintViolationException) {
            throw new ForumRuleViolation('forum_slug_taken');
        }
    }

    public function delete(int $id): void
    {
        $this->connection->transactional(static function (Connection $connection) use ($id): void {
            try {
                // Nested: a savepoint, so the failed delete does not abort the transaction (PostgreSQL).
                $deleted = $connection->transactional(
                    static fn(Connection $inner): int|string => $inner->delete('forums', ['id' => $id]),
                );
            } catch (ForeignKeyConstraintViolationException) {
                $children = $connection->fetchOne('SELECT COUNT(*) FROM forums WHERE parent_id = ?', [$id]);

                throw new ForumRuleViolation((int) $children > 0 ? 'forum_has_children' : 'forum_has_threads');
            }

            if ($deleted === 0) {
                throw new ForumRuleViolation('forum_not_found');
            }

            VersionCounter::bump($connection, VersionCounter::PERMISSION_RULES);
        });
    }

    public function listIn(ForumScope $scope, int $limit, int $offset = 0): array
    {
        [$condition, $parameters, $types] = ScopeSql::forums($scope, 'id');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM forums WHERE ' . $condition
                . ' ORDER BY depth, sort_order, id LIMIT ' . max(0, $limit) . ' OFFSET ' . max(0, $offset),
            $parameters,
            $types,
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function countIn(ForumScope $scope): int
    {
        [$condition, $parameters, $types] = ScopeSql::forums($scope, 'id');

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM forums WHERE ' . $condition,
            $parameters,
            $types,
        );
    }

    /**
     * @return array<int, int|null> Forum ID => parent ID, rows locked until the transaction ends.
     */
    private function lockedTree(Connection $connection): array
    {
        $tree = [];

        foreach ($connection->fetchAllAssociative('SELECT id, parent_id FROM forums FOR UPDATE') as $row) {
            $tree[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }

        return $tree;
    }

    /**
     * @param array<int, int|null> $tree
     */
    private function depthUnder(array $tree, int $parentId): int
    {
        if (!array_key_exists($parentId, $tree)) {
            throw new ForumRuleViolation('forum_parent_not_found');
        }

        $depth = self::depthOf($tree, $parentId) + 1;

        if ($depth > $this->maxDepth) {
            throw new ForumRuleViolation('forum_too_deep');
        }

        return $depth;
    }

    /**
     * @param array<int, int|null> $tree
     */
    private static function depthOf(array $tree, int $id): int
    {
        $depth = 0;

        for ($parent = $tree[$id] ?? null; $parent !== null; $parent = $tree[$parent] ?? null) {
            ++$depth;
        }

        return $depth;
    }

    /**
     * @param array<int, int|null> $tree
     *
     * @return list<int> The forum and all its descendants.
     */
    private static function subtree(array $tree, int $id): array
    {
        $nodes = [$id];

        for ($index = 0; $index < count($nodes); ++$index) {
            foreach ($tree as $child => $parent) {
                if ($parent === $nodes[$index]) {
                    $nodes[] = $child;
                }
            }
        }

        return $nodes;
    }

    /**
     * @param array<int, list<Forum>> $children Parent ID (0 for roots) => sorted children.
     *
     * @return list<Forum>
     */
    private static function flatten(array $children, int $parent): array
    {
        $ordered = [];

        foreach ($children[$parent] ?? [] as $forum) {
            $ordered[] = $forum;
            $ordered = [...$ordered, ...self::flatten($children, $forum->id)];
        }

        return $ordered;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Forum
    {
        return new Forum(
            (int) $row['id'],
            $row['parent_id'] === null ? null : (int) $row['parent_id'],
            ForumType::from((string) $row['type']),
            (string) $row['title'],
            (string) $row['slug'],
            (int) $row['sort_order'],
            (int) $row['depth'],
            (int) $row['is_locked'] === 1,
            isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
            (int) ($row['is_questions'] ?? 0) === 1,
            isset($row['link_url']) && $row['link_url'] !== '' ? (string) $row['link_url'] : null,
            (int) ($row['link_counts'] ?? 0) === 1,
            (int) ($row['link_clicks'] ?? 0),
            (int) ($row['counts_posts'] ?? 1) === 1,
            isset($row['password_hash']) && $row['password_hash'] !== '',
            (int) ($row['guest_posting'] ?? 0) === 1,
        );
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
