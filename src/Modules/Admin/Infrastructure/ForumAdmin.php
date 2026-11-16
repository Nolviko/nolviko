<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Events\Outbox;
use Nolviko\Modules\Forums\Domain\Forum;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumRuleViolation;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Threads\Infrastructure\AuditTrail;

/**
 * Forum management for administrators (M04.05), the keyboard and no-JavaScript way to do what a drag-and-drop
 * tree would: create, move under another parent, one step up or down among siblings, lock and delete. The tree
 * rules (depth, cycles, empty before delete) stay in ForumRepository; every change is audited.
 */
final class ForumAdmin
{
    public const MAX_TITLE = 100;

    private readonly AuditTrail $audit;

    public function __construct(
        private readonly ForumRepository $forums,
        private readonly Connection $connection,
    ) {
        $this->audit = new AuditTrail($connection, new Outbox($connection));
    }

    /**
     * @throws ForumRuleViolation `forum_title_invalid` and the repository's create diagnostics.
     */
    public function create(int $actorId, ForumType $type, string $title, string $slug, ?int $parentId): Forum
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        if ($title === '' || mb_strlen($title) > self::MAX_TITLE || !mb_check_encoding($title, 'UTF-8')) {
            throw new ForumRuleViolation('forum_title_invalid');
        }

        $siblings = $this->siblings($parentId);
        $last = $siblings === [] ? 0 : $siblings[count($siblings) - 1]->sortOrder + 10;
        return $this->connection->transactional(function () use ($actorId, $type, $title, $slug, $parentId, $last) {
            $forum = $this->forums->create($type, $title, strtolower(trim($slug)), $parentId, $last);
            $this->record('forum.create', 'forum.created', $actorId, $forum->id, ['title' => $title]);

            return $forum;
        });
    }

    /**
     * @throws ForumRuleViolation The repository's move diagnostics.
     */
    public function moveUnder(int $actorId, int $id, ?int $parentId): void
    {
        $siblings = $this->siblings($parentId);
        $last = $siblings === [] ? 0 : $siblings[count($siblings) - 1]->sortOrder + 10;
        $this->connection->transactional(function () use ($actorId, $id, $parentId, $last): void {
            $this->forums->move($id, $parentId, $last);
            $this->record('forum.move', 'forum.moved', $actorId, $id, ['parent_id' => $parentId]);
        });
    }

    /**
     * One place up (-1) or down (+1) among the forum's siblings; at either end nothing changes.
     *
     * @throws ForumRuleViolation `forum_not_found`.
     */
    public function shift(int $actorId, int $id, int $direction): void
    {
        $forum = $this->forums->find($id) ?? throw new ForumRuleViolation('forum_not_found');
        $siblings = $this->siblings($forum->parentId);
        $ids = array_map(static fn(Forum $sibling): int => $sibling->id, $siblings);
        $index = (int) array_search($id, $ids, true);
        $target = $index + ($direction < 0 ? -1 : 1);

        if ($target < 0 || $target >= count($ids)) {
            return;
        }

        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        $this->connection->transactional(function () use ($ids, $forum, $actorId, $id, $target): void {
            // Sort orders are rewritten in steps of ten, so equal or crowded values cannot block a swap.
            foreach ($ids as $position => $siblingId) {
                $this->forums->move($siblingId, $forum->parentId, ($position + 1) * 10);
            }

            $this->record('forum.reorder', 'forum.reordered', $actorId, $id, ['position' => $target + 1]);
        });
    }

    /**
     * @throws ForumRuleViolation `forum_not_found`.
     */
    public function update(
        int $actorId,
        int $id,
        string $title,
        string $slug,
        ?string $description,
        ?bool $questions = null,
    ): void {
        $this->connection->transactional(function () use (
            $actorId,
            $id,
            $title,
            $slug,
            $description,
            $questions,
        ): void {
            $this->forums->update($id, $title, $slug, $description, $questions);
            $this->record('forum.update', 'forum.updated', $actorId, $id, ['title' => trim($title), 'slug' => $slug]
                + ($questions === null ? [] : ['questions' => $questions]));
        });
    }

    public function setLocked(int $actorId, int $id, bool $locked): void
    {
        $this->connection->transactional(fn() => $this->lock($actorId, $id, $locked));
    }

    private function lock(int $actorId, int $id, bool $locked): void
    {
        $this->forums->setLocked($id, $locked);
        $this->record(
            $locked ? 'forum.lock' : 'forum.unlock',
            $locked ? 'forum.locked' : 'forum.unlocked',
            $actorId,
            $id,
            [],
        );
    }

    /**
     * @throws ForumRuleViolation `forum_not_found`, `forum_has_children` or `forum_has_threads`.
     */
    public function delete(int $actorId, int $id): void
    {
        $forum = $this->forums->find($id) ?? throw new ForumRuleViolation('forum_not_found');
        $this->connection->transactional(function () use ($actorId, $id, $forum): void {
            $this->forums->delete($id);
            $this->record('forum.delete', 'forum.deleted', $actorId, $id, ['title' => $forum->title]);
        });
    }

    /**
     * @return list<Forum> The children of a parent (or the roots for null), in display order.
     */
    private function siblings(?int $parentId): array
    {
        return array_values(array_filter(
            $this->forums->all(),
            static fn(Forum $forum): bool => $forum->parentId === $parentId,
        ));
    }

    /**
     * @param array<string, int|string|bool|null> $details
     */
    private function record(string $action, string $event, int $actorId, int $forumId, array $details): void
    {
        $at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $this->audit->record($action, $event, 'forum', $forumId, $actorId, null, $at, $details);
    }
}
