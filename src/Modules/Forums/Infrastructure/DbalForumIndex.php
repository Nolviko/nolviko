<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Forums\Domain\ForumIndex;
use Nolviko\Modules\Forums\Domain\ForumIndexEntry;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Infrastructure\ScopeSql;

/**
 * ForumIndex on DBAL: forums in the visible scope joined to their statistics row and, through its
 * `last_post_id`, to the last post's thread and author. Every join is a primary-key lookup per forum (the
 * forum count is small and has no LIMIT, so there is no page to cut first); tree order is built in PHP.
 */
final class DbalForumIndex implements ForumIndex
{
    private const SQL = 'SELECT f.id, f.parent_id, f.type, f.title, f.slug, f.depth, f.sort_order, f.description, '
        . 's.thread_count, s.post_count, s.last_post_at, t.id AS last_thread_id, t.title AS last_thread_title, '
        . 't.slug AS last_thread_slug, '
        . 'u.username AS last_poster_name '
        . 'FROM forums f LEFT JOIN forum_statistics s ON s.forum_id = f.id '
        . 'LEFT JOIN posts p ON p.id = s.last_post_id LEFT JOIN threads t ON t.id = p.thread_id '
        . 'LEFT JOIN users u ON u.id = p.user_id WHERE ';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function entries(ForumScope $visible, ForumScope $readable): array
    {
        [$condition, $parameters, $types] = ScopeSql::forums($visible, 'f.id');
        $rows = $this->connection->fetchAllAssociative(
            self::SQL . $condition . ' ORDER BY f.sort_order, f.id',
            $parameters,
            $types,
        );
        $children = [];

        foreach ($rows as $row) {
            $children[$row['parent_id'] === null ? 0 : (int) $row['parent_id']][] = $row;
        }

        $entries = [];
        self::walk($children, 0, $readable, $entries);

        return $entries;
    }

    /**
     * Depth-first in sort order. Rows whose parent is outside the scope are never reached; the scope already
     * hides children of hidden forums (ADR-011 inheritance).
     *
     * @param array<int, list<array<string, mixed>>> $children
     * @param list<ForumIndexEntry> $entries
     */
    private static function walk(array $children, int $parent, ForumScope $readable, array &$entries): void
    {
        foreach ($children[$parent] ?? [] as $row) {
            $entries[] = self::entry($row, $readable->contains((int) $row['id']));
            self::walk($children, (int) $row['id'], $readable, $entries);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function entry(array $row, bool $readable): ForumIndexEntry
    {
        $last = $readable && $row['last_thread_id'] !== null;

        return new ForumIndexEntry(
            (int) $row['id'],
            $row['parent_id'] === null ? null : (int) $row['parent_id'],
            ForumType::from((string) $row['type']),
            (string) $row['title'],
            (string) $row['slug'],
            (int) $row['depth'],
            (int) ($row['thread_count'] ?? 0),
            (int) ($row['post_count'] ?? 0),
            $last && $row['last_post_at'] !== null
                ? new DateTimeImmutable((string) $row['last_post_at'], new DateTimeZone('UTC'))
                : null,
            $last ? (int) $row['last_thread_id'] : null,
            $last ? (string) $row['last_thread_title'] : null,
            $last && $row['last_poster_name'] !== null ? (string) $row['last_poster_name'] : null,
            isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
            $last && isset($row['last_thread_slug']) ? (string) $row['last_thread_slug'] : null,
        );
    }
}
