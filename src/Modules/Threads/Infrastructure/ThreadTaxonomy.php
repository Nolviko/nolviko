<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * Thread prefixes and tags (2026-11-08). Administrators define them (`/admin/taxonomy`, audited); a thread's
 * author picks them when starting it and moderators change them later. Lists read the labels of a whole page
 * of threads in two queries (forThreads). Prefix colours are a fixed set of chip styles, never free CSS.
 */
final readonly class ThreadTaxonomy
{
    public const COLORS = ['neutral', 'accent', 'blue', 'amber', 'danger'];

    public const MAX_TAGS = 5;

    public const MAX_LABEL = 40;

    /** Chip class of each prefix colour (theme.css). */
    public const CLASSES = ['neutral' => 'chip', 'accent' => 'chip chip-new', 'blue' => 'chip chip-blue',
        'amber' => 'chip chip-amber', 'danger' => 'chip chip-danger'];

    public function __construct(private Connection $db)
    {
    }

    /**
     * Prefixes a thread of $forumId may carry: that forum's and the board-wide ones.
     *
     * @return list<array{id: int, label: string, color: string, class: string, forum_id: int|null}>
     */
    public function prefixes(?int $forumId = null, bool $all = false): array
    {
        $rows = $all
            ? $this->db->fetchAllAssociative(
                'SELECT id, label, color, forum_id FROM thread_prefixes ORDER BY label, id',
            )
            : $this->db->fetchAllAssociative(
                'SELECT id, label, color, forum_id FROM thread_prefixes WHERE forum_id IS NULL OR forum_id = ? '
                    . 'ORDER BY label, id',
                [$forumId ?? 0],
            );

        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'label' => (string) $row['label'],
            'color' => (string) $row['color'], 'class' => self::CLASSES[(string) $row['color']] ?? 'chip',
            'forum_id' => $row['forum_id'] === null ? null : (int) $row['forum_id']], $rows);
    }

    /**
     * @return list<array{id: int, name: string, slug: string}>
     */
    public function tags(): array
    {
        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name'],
            'slug' => (string) $row['slug']], $this->db->fetchAllAssociative('SELECT id, name, slug FROM tags '
            . 'ORDER BY name, id LIMIT 500'));
    }

    /** @return array{id: int, name: string, slug: string}|null */
    public function tag(string $slug): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id, name, slug FROM tags WHERE slug = ?', [$slug]);

        return $row === false ? null : ['id' => (int) $row['id'], 'name' => (string) $row['name'],
            'slug' => (string) $row['slug']];
    }

    /**
     * Prefix and tags of each thread, for list and thread pages.
     *
     * @param list<int> $threadIds
     * @return array<int, array{prefix: array{id: int, label: string, class: string}|null,
     *     tags: list<array{id: int, name: string, slug: string}>}>
     */
    public function forThreads(array $threadIds): array
    {
        $threadIds = array_values(array_unique(array_filter($threadIds, static fn(int $id): bool => $id > 0)));

        if ($threadIds === []) {
            return [];
        }

        $result = array_fill_keys($threadIds, ['prefix' => null, 'tags' => []]);
        $prefixes = $this->db->fetchAllAssociative(
            'SELECT t.id, p.id AS prefix_id, p.label, p.color FROM threads t '
                . 'INNER JOIN thread_prefixes p ON p.id = t.prefix_id '
                . 'WHERE t.id IN (?)',
            [$threadIds],
            [ArrayParameterType::INTEGER],
        );

        foreach ($prefixes as $row) {
            $result[(int) $row['id']]['prefix'] = ['id' => (int) $row['prefix_id'], 'label' => (string) $row['label'],
                'class' => self::CLASSES[(string) $row['color']] ?? 'chip'];
        }

        $tags = $this->db->fetchAllAssociative(
            'SELECT tt.thread_id, g.id, g.name, g.slug FROM thread_tags tt INNER JOIN tags g ON g.id = tt.tag_id '
                . 'WHERE tt.thread_id IN (?) ORDER BY g.name, g.id',
            [$threadIds],
            [ArrayParameterType::INTEGER],
        );

        foreach ($tags as $row) {
            $result[(int) $row['thread_id']]['tags'][] = ['id' => (int) $row['id'], 'name' => (string) $row['name'],
                'slug' => (string) $row['slug']];
        }

        return $result;
    }

    /**
     * Checks a choice before the thread exists, so a refused choice stops the whole form.
     *
     * @param list<int> $tagIds
     * @return array{int|null, list<int>}
     *
     * @throws ThreadRuleViolation `thread_prefix_invalid`, `thread_prefix_required` (a new thread in a forum with
     *     prefixes while Site settings require one) or `thread_tags_invalid`.
     */
    public function validate(int $forumId, ?int $prefixId, array $tagIds, bool $newThread = false): array
    {
        $tagIds = array_values(array_unique(array_filter($tagIds, static fn(int $id): bool => $id > 0)));
        $available = array_column($this->prefixes($forumId), 'id');

        if ($prefixId !== null && !in_array($prefixId, $available, true)) {
            throw new ThreadRuleViolation('thread_prefix_invalid');
        }

        if ($newThread && $prefixId === null && $available !== [] && $this->prefixRequired()) {
            throw new ThreadRuleViolation('thread_prefix_required');
        }

        if (
            count($tagIds) > self::MAX_TAGS || ($tagIds !== [] && (int) $this->db->fetchOne(
                'SELECT COUNT(*) FROM tags WHERE id IN (?)',
                [$tagIds],
                [ArrayParameterType::INTEGER],
            ) !== count($tagIds))
        ) {
            throw new ThreadRuleViolation('thread_tags_invalid', ['max' => self::MAX_TAGS]);
        }

        return [$prefixId, $tagIds];
    }

    public function prefixRequired(): bool
    {
        return $this->db->fetchOne(
            "SELECT setting_value FROM settings WHERE setting_key = 'threads.prefix_required'",
        ) === 'on';
    }

    /**
     * Sets the thread's prefix and tags (after validate()). Audited when a moderator changes another's thread.
     *
     * @param list<int> $tagIds
     */
    public function assign(int $threadId, int $forumId, ?int $prefixId, array $tagIds, ?int $auditActor = null): void
    {
        [$prefixId, $tagIds] = $this->validate($forumId, $prefixId, $tagIds);
        $this->db->transactional(function (Connection $db) use ($threadId, $prefixId, $tagIds, $auditActor): void {
            $db->update('threads', ['prefix_id' => $prefixId], ['id' => $threadId]);
            $db->delete('thread_tags', ['thread_id' => $threadId]);

            foreach ($tagIds as $tagId) {
                $db->insert('thread_tags', ['thread_id' => $threadId, 'tag_id' => $tagId]);
            }

            if ($auditActor !== null) {
                $this->audit($db, $auditActor, 'thread.taxonomy', 'thread', $threadId, [
                    'prefix_id' => $prefixId,
                    'tag_ids' => $tagIds,
                ]);
            }
        });
    }

    /**
     * @throws ThreadRuleViolation `taxonomy_label_invalid`, `taxonomy_color_invalid` or `taxonomy_tag_taken`.
     */
    public function createPrefix(string $label, string $color, ?int $forumId, int $actorId): void
    {
        $label = self::label($label);

        if (!in_array($color, self::COLORS, true)) {
            throw new ThreadRuleViolation('taxonomy_color_invalid');
        }

        $this->db->transactional(function (Connection $db) use ($label, $color, $forumId, $actorId): void {
            $db->insert('thread_prefixes', ['forum_id' => $forumId, 'label' => $label, 'color' => $color,
                'created_at' => self::now($db)]);
            $this->audit($db, $actorId, 'taxonomy.prefix_created', 'prefix', (int) $db->lastInsertId(), [
                'label' => $label,
                'forum_id' => $forumId,
            ]);
        });
    }

    public function createTag(string $name, int $actorId): void
    {
        $name = self::label($name);
        $slug = CreateThread::slug($name);

        if ($slug === '') {
            throw new ThreadRuleViolation('taxonomy_label_invalid');
        }

        try {
            $this->db->transactional(function (Connection $db) use ($name, $slug, $actorId): void {
                $db->insert('tags', ['name' => $name, 'slug' => $slug, 'created_at' => self::now($db)]);
                $this->audit($db, $actorId, 'taxonomy.tag_created', 'tag', (int) $db->lastInsertId(), [
                    'name' => $name,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ThreadRuleViolation('taxonomy_tag_taken');
        }
    }

    /** Removes a prefix or tag; threads lose it (foreign keys). */
    public function delete(string $kind, int $id, int $actorId): void
    {
        $table = $kind === 'tag' ? 'tags' : 'thread_prefixes';
        $this->db->transactional(function (Connection $db) use ($table, $kind, $id, $actorId): void {
            if ($db->delete($table, ['id' => $id]) === 1) {
                $this->audit($db, $actorId, 'taxonomy.' . $kind . '_deleted', $kind, $id, []);
            }
        });
    }

    private static function label(string $label): string
    {
        return CreateThread::title($label) !== null && mb_strlen(trim($label)) <= self::MAX_LABEL
            ? (string) CreateThread::title($label)
            : throw new ThreadRuleViolation('taxonomy_label_invalid', ['max' => self::MAX_LABEL]);
    }

    private static function now(Connection $db): string
    {
        return (string) $db->fetchOne('SELECT UTC_TIMESTAMP(6)');
    }

    /** @param array<string, mixed> $details */
    private function audit(Connection $db, int $actor, string $action, string $type, int $id, array $details): void
    {
        $db->insert('audit_logs', ['actor_id' => $actor, 'action' => $action, 'target_type' => $type,
            'target_id' => $id, 'reason' => null, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => self::now($db)]);
    }
}
