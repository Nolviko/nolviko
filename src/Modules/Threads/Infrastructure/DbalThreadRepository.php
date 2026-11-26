<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Infrastructure\ScopeSql;
use Nolviko\Modules\Threads\Domain\Thread;
use Nolviko\Modules\Threads\Domain\ThreadCursor;
use Nolviko\Modules\Threads\Domain\ThreadListItem;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Domain\ThreadStatus;

/**
 * ThreadRepository on DBAL: each list is a single query (threads joined to the author and last poster), the
 * scope is in the WHERE clause, and the limit is capped at MAX_LIST.
 *
 * The page of thread rows is cut first in a derived table and only those rows are joined to `users`. Joined
 * directly, MySQL may choose hash joins and then read and sort every matching thread before the LIMIT (seen on
 * 10,000 threads: 8,500 rows, 22 ms, against 30 rows, 1 ms); a derived table with LIMIT is never merged, so its
 * ORDER BY ... LIMIT always walks the list index.
 */
final class DbalThreadRepository implements ThreadRepository
{
    /** Member-chosen forum list orders (roadmap 14), each served by an index of migration 0066. */
    private const SORTS = [
        'title' => 'is_sticky DESC, title ASC, id ASC',
        'replies' => 'is_sticky DESC, reply_count DESC, id DESC',
        'started' => 'is_sticky DESC, created_at DESC, id DESC',
    ];

    private const PAGE_COLUMNS = 't.id, t.forum_id, t.user_id, t.last_poster_id, t.title, t.slug, t.is_sticky, '
        . 't.is_locked, t.last_post_at, t.status, t.moved_to_thread_id, t.reply_count, t.view_count';

    private const LIST_COLUMNS = 'page.id, page.forum_id, page.title, page.slug, page.is_sticky, page.is_locked, '
        . 'page.last_post_at, page.status, page.moved_to_thread_id, page.reply_count, page.view_count, '
        . 'page.last_poster_id, '
        . 'author.username AS author_name, '
        . 'poster.username AS last_poster_name';

    private const LIST_JOINS = ' LEFT JOIN users author ON author.id = page.user_id '
        . 'LEFT JOIN users poster ON poster.id = page.last_poster_id';

    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function create(
        int $forumId,
        ?int $userId,
        string $title,
        string $slug,
        DateTimeImmutable $createdAt,
        ThreadStatus $status = ThreadStatus::Visible,
    ): int {
        $type = $this->connection->fetchOne('SELECT type FROM forums WHERE id = ?', [$forumId]);

        if ($type === false) {
            throw new ThreadRuleViolation('thread_forum_not_found');
        }

        if ($type !== 'forum') {
            throw new ThreadRuleViolation('thread_forum_is_category');
        }

        $at = $createdAt->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
        $this->connection->insert('threads', [
            'forum_id' => $forumId,
            'user_id' => $userId,
            'title' => $title,
            'slug' => $slug,
            'status' => $status->value,
            'last_post_at' => $at,
            'last_poster_id' => $userId,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function find(int $id, ?int $reader = null): ?Thread
    {
        $readMark = $reader === null ? '' : ', r.last_read_post_id';
        $readJoin = $reader === null ? '' : 'LEFT JOIN thread_reads r ON r.thread_id = t.id AND r.user_id = ? ';
        $row = $this->connection->fetchAssociative(
            'SELECT t.id, t.forum_id, t.user_id, t.title, t.slug, t.status, t.is_locked, t.is_sticky, '
                . 't.reply_count, t.first_post_id, t.last_post_id, t.moved_to_thread_id' . $readMark
                . ' FROM threads t ' . $readJoin . 'WHERE t.id = ?',
            $reader === null ? [$id] : [$reader, $id],
        );

        if ($row === false) {
            return null;
        }

        return new Thread(
            (int) $row['id'],
            (int) $row['forum_id'],
            $row['user_id'] === null ? null : (int) $row['user_id'],
            (string) $row['title'],
            (string) $row['slug'],
            ThreadStatus::from((string) $row['status']),
            (int) $row['is_locked'] === 1,
            (int) $row['is_sticky'] === 1,
            (int) $row['reply_count'],
            $row['first_post_id'] === null ? null : (int) $row['first_post_id'],
            $row['last_post_id'] === null ? null : (int) $row['last_post_id'],
            $row['moved_to_thread_id'] === null ? null : (int) $row['moved_to_thread_id'],
            isset($row['last_read_post_id']) ? (int) $row['last_read_post_id'] : null,
        );
    }

    public function forumThreads(
        ForumScope $scope,
        int $forumId,
        int $limit,
        bool $withDeleted = false,
        ?ThreadCursor $after = null,
        ?ThreadCursor $before = null,
    ): array {
        if (!$scope->contains($forumId)) {
            return [];
        }

        $cursor = $after ?? $before;
        // Newer pages are read in ascending order next to the cursor and turned round.
        $direction = $before === null ? 'DESC' : 'ASC';
        [$seek, $seekParameters] = $cursor === null ? ['', []] : self::seek($cursor, $before === null);
        $parameters = [
            $forumId,
            ThreadStatus::Visible->value,
            ...($withDeleted ? [ThreadStatus::Hidden->value] : []),
            ...$seekParameters,
        ];
        $rows = $this->connection->fetchAllAssociative(
            self::page(
                'WHERE t.forum_id = ? AND t.status IN (?' . ($withDeleted ? ', ?' : '') . ')' . $seek,
                'is_sticky ' . $direction . ', last_post_at ' . $direction . ', id ' . $direction,
                self::limit($limit),
            ),
            $parameters,
            self::types($parameters),
        );
        $items = array_map(self::hydrate(...), $rows);

        return $before === null ? $items : array_reverse($items);
    }

    public function forumThreadsSorted(
        ForumScope $scope,
        int $forumId,
        string $sort,
        int $limit,
        int $offset,
        bool $withDeleted = false,
    ): array {
        $order = self::SORTS[$sort] ?? null;
        if ($order === null || !$scope->contains($forumId) || $offset < 0) {
            return [];
        }

        $parameters = [
            $forumId,
            ThreadStatus::Visible->value,
            ...($withDeleted ? [ThreadStatus::Hidden->value] : []),
        ];
        $rows = $this->connection->fetchAllAssociative(
            self::page(
                'WHERE t.forum_id = ? AND t.status IN (?' . ($withDeleted ? ', ?' : '') . ')',
                $order,
                self::limit($limit),
                $offset,
            ),
            $parameters,
            self::types($parameters),
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function latest(ForumScope $scope, int $limit, ?ThreadCursor $after = null, ?int $tagId = null): array
    {
        [$condition, $parameters, $types] = ScopeSql::forums($scope, 't.forum_id');
        $seek = $after === null ? [] : [$after->timestamp(), $after->timestamp(), $after->threadId];

        if ($tagId !== null) {
            $condition .= ' AND EXISTS (SELECT 1 FROM thread_tags tt WHERE tt.thread_id = t.id AND tt.tag_id = ?)';
            $parameters[] = $tagId;
            $types[] = ParameterType::INTEGER;
        }

        $rows = $this->connection->fetchAllAssociative(
            self::page(
                'WHERE t.status = ? AND t.moved_to_thread_id IS NULL AND ' . $condition
                    . ($after === null ? '' : ' AND t.last_post_at <= ? AND (t.last_post_at < ? OR t.id < ?)'),
                'last_post_at DESC, id DESC',
                self::limit($limit),
            ),
            [ThreadStatus::Visible->value, ...$parameters, ...$seek],
            [ParameterType::STRING, ...$types, ...self::types($seek)],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function countVisible(ForumScope $scope): int
    {
        [$condition, $parameters, $types] = ScopeSql::forums($scope, 't.forum_id');

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM threads t WHERE t.status = ? AND ' . $condition,
            [ThreadStatus::Visible->value, ...$parameters],
            [ParameterType::STRING, ...$types],
        );
    }

    /**
     * The keyset condition for rows beyond $cursor. `is_sticky` has two values, so instead of one OR over all
     * three keys (which MySQL answered with a full scan and filesort) the condition is an equality on the
     * cursor's sticky group plus a `last_post_at` range, which `ix_threads_forum_list` reads in order; only the
     * crossing from the sticky to the normal group (or back) adds the whole other group.
     *
     * @return array{string, list<int|string>}
     */
    private static function seek(ThreadCursor $cursor, bool $older): array
    {
        $operator = $older ? '<' : '>';
        $edge = $older ? '<=' : '>=';
        $within = 't.is_sticky = ? AND t.last_post_at ' . $edge . ' ? AND (t.last_post_at ' . $operator . ' ? '
            . 'OR t.id ' . $operator . ' ?)';
        $parameters = [(int) $cursor->sticky, $cursor->timestamp(), $cursor->timestamp(), $cursor->threadId];

        // Older than a sticky row: every normal row follows. Newer than a normal row: every sticky row precedes.
        if ($cursor->sticky === $older) {
            return [' AND ((' . $within . ') OR t.is_sticky = ?)', [...$parameters, $older ? 0 : 1]];
        }

        return [' AND ' . $within, $parameters];
    }

    /**
     * @param string $order Columns without table alias; applied to the page and again to the joined result.
     */
    private static function page(string $where, string $order, int $limit, int $offset = 0): string
    {
        $inner = 't.' . str_replace(', ', ', t.', $order);
        $outer = 'page.' . str_replace(', ', ', page.', $order);

        return 'SELECT ' . self::LIST_COLUMNS . ' FROM (SELECT ' . self::PAGE_COLUMNS . ' FROM threads t '
            . $where . ' ORDER BY ' . $inner . ' LIMIT ' . $limit . ($offset > 0 ? ' OFFSET ' . $offset : '')
            . ') page' . self::LIST_JOINS
            . ' ORDER BY ' . $outer;
    }

    /**
     * Integers bound as integers: MariaDB compares an integer column with a quoted string parameter without
     * reading the index in order, and then sorts the whole range (seen on the keyset seek).
     *
     * @param list<int|string> $parameters
     *
     * @return list<ParameterType>
     */
    public static function types(array $parameters): array
    {
        return array_map(
            static fn(int|string $value): ParameterType => is_int($value)
                ? ParameterType::INTEGER
                : ParameterType::STRING,
            $parameters,
        );
    }

    private static function limit(int $limit): int
    {
        return max(1, min($limit, self::MAX_LIST));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): ThreadListItem
    {
        return new ThreadListItem(
            (int) $row['id'],
            (int) $row['forum_id'],
            (string) $row['title'],
            (string) $row['slug'],
            (bool) $row['is_sticky'],
            (bool) $row['is_locked'],
            $row['last_post_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['last_post_at'], new DateTimeZone('UTC')),
            $row['author_name'] === null ? null : (string) $row['author_name'],
            $row['last_poster_name'] === null ? null : (string) $row['last_poster_name'],
            ThreadStatus::from((string) $row['status']),
            $row['moved_to_thread_id'] === null ? null : (int) $row['moved_to_thread_id'],
            (int) ($row['reply_count'] ?? 0),
            (int) ($row['view_count'] ?? 0),
            isset($row['last_poster_id']) ? (int) $row['last_poster_id'] : null,
        );
    }
}
