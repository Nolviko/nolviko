<?php

declare(strict_types=1);

namespace Nolviko\Modules\Calendar\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Throwable;

/**
 * Calendar events bound to threads (beta.18 roadmap 38): one date or date range per thread, no recurrence. An event
 * is as visible as its thread: the month view reads only visible threads of forums in the reader's readable scope,
 * so private and password forums stay hidden without extra rules.
 */
final class CalendarEvents
{
    public const MAX_DAYS = 366;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Sets or (with an empty start) removes the event of a thread. The caller checks that the actor may manage it.
     *
     * @return bool False for invalid dates (start after end, a range over MAX_DAYS, outside 1900-2200).
     */
    public function set(int $threadId, ?int $userId, string $start, string $end): bool
    {
        $start = trim($start);

        if ($start === '') {
            $this->db->delete('thread_events', ['thread_id' => $threadId]);

            return true;
        }

        $from = self::date($start);
        $to = trim($end) === '' ? $from : self::date(trim($end));

        if (
            $from === null || $to === null || $to < $from || $from->diff($to)->days > self::MAX_DAYS
            || (int) $from->format('Y') < 1900 || (int) $to->format('Y') > 2200
        ) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s.000000');
        $this->db->transactional(static function (Connection $db) use ($threadId, $userId, $from, $to, $now): void {
            $db->delete('thread_events', ['thread_id' => $threadId]);
            $db->insert('thread_events', ['thread_id' => $threadId, 'user_id' => $userId,
                'starts_on' => $from->format('Y-m-d'), 'ends_on' => $to->format('Y-m-d'), 'created_at' => $now,
                'updated_at' => $now]);
        });

        return true;
    }

    /** @return array{starts_on: string, ends_on: string}|null */
    public function of(int $threadId): ?array
    {
        try {
            $row = $this->db->fetchAssociative(
                'SELECT starts_on, ends_on FROM thread_events WHERE thread_id = ?',
                [$threadId],
            );
        } catch (Throwable) {
            return null;
        }

        return $row === false ? null
            : ['starts_on' => (string) $row['starts_on'], 'ends_on' => (string) $row['ends_on']];
    }

    /**
     * Events overlapping the month, earliest first.
     *
     * @return list<array{thread_id: int, title: string, slug: ?string, starts_on: string, ends_on: string}>
     */
    public function month(ForumScope $scope, DateTimeImmutable $first): array
    {
        if ($scope->forumIds === []) {
            return [];
        }

        $last = $first->modify('last day of this month');

        return array_map(
            static fn(array $row): array => ['thread_id' => (int) $row['thread_id'],
            'title' => (string) $row['title'], 'slug' => $row['slug'] === null ? null : (string) $row['slug'],
            'starts_on' => (string) $row['starts_on'], 'ends_on' => (string) $row['ends_on']],
            $this->db->fetchAllAssociative(
                'SELECT e.thread_id, t.title, t.slug, e.starts_on, e.ends_on FROM thread_events e '
                    . "INNER JOIN threads t ON t.id = e.thread_id WHERE t.status = 'visible' AND t.deleted_at IS NULL "
                    . 'AND t.forum_id IN (?) AND e.starts_on <= ? AND e.ends_on >= ? '
                    . 'ORDER BY e.starts_on, e.thread_id LIMIT 300',
                [$scope->forumIds, $last->format('Y-m-d'), $first->format('Y-m-d')],
                [ArrayParameterType::INTEGER],
            )
        );
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
