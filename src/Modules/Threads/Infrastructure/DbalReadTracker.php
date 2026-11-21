<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Core\Database\SqlDialect;
use Nolviko\Modules\Threads\Domain\ReadTracker;

/**
 * ReadTracker on DBAL. Writes are an UPDATE that only moves forward, followed by INSERT IGNORE when there was no
 * row yet (portable across MySQL and MariaDB, no upsert dialect). Reading a forum page costs two primary-key
 * range lookups: the forum cutoff and the rows of the page's threads.
 */
final class DbalReadTracker implements ReadTracker
{
    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function markThread(int $userId, int $threadId, int $lastPostId, DateTimeImmutable $at): void
    {
        $stamp = self::stamp($at);
        // 2026-11-21 benchmark: write only when the mark moves forward. Before, re-reading a thread without new posts
        // changed no row, so MySQL reported 0 and the INSERT IGNORE below ran on every view: a committed write whose
        // foreign-key check waited for the thread row that concurrent replies lock.
        $updated = $this->connection->executeStatement(
            'UPDATE thread_reads SET last_read_post_id = ?, read_at = GREATEST(read_at, ?)'
                . ' WHERE user_id = ? AND thread_id = ? AND last_read_post_id < ?',
            [$lastPostId, $stamp, $userId, $threadId, $lastPostId],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER,
                ParameterType::INTEGER],
        );

        $exists = $updated > 0 || $this->connection->fetchOne(
            'SELECT 1 FROM thread_reads WHERE user_id = ? AND thread_id = ?',
            [$userId, $threadId],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        ) !== false;

        if (!$exists) {
            $this->connection->executeStatement(
                SqlDialect::insertIgnore(
                    $this->connection,
                    'INSERT INTO thread_reads (user_id, thread_id, last_read_post_id, read_at) VALUES (?, ?, ?, ?)',
                ),
                [$userId, $threadId, $lastPostId, $stamp],
                [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING],
            );
        }
    }

    public function markForum(int $userId, int $forumId, DateTimeImmutable $at): void
    {
        $stamp = self::stamp($at);
        $this->connection->transactional(static function (Connection $connection) use (
            $userId,
            $forumId,
            $stamp,
        ): void {
            $updated = $connection->executeStatement(
                'UPDATE forum_reads SET read_cutoff = GREATEST(read_cutoff, ?) WHERE user_id = ? AND forum_id = ?',
                [$stamp, $userId, $forumId],
            );

            if ($updated === 0) {
                $connection->executeStatement(
                    SqlDialect::insertIgnore(
                        $connection,
                        'INSERT INTO forum_reads (user_id, forum_id, read_cutoff) VALUES (?, ?, ?)',
                    ),
                    [$userId, $forumId, $stamp],
                );
            }

            $connection->executeStatement(
                'DELETE FROM thread_reads WHERE user_id = ? '
                    . 'AND thread_id IN (SELECT id FROM threads WHERE forum_id = ?)',
                [$userId, $forumId],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );
        });
    }

    public function unread(int $userId, int $forumId, array $lastPosts, DateTimeImmutable $now): array
    {
        return $this->unreadAcross(
            $userId,
            array_map(static fn(?DateTimeImmutable $lastPost): array => [$forumId, $lastPost], $lastPosts),
            $now,
        );
    }

    public function unreadAcross(int $userId, array $threads, DateTimeImmutable $now): array
    {
        if ($threads === []) {
            return [];
        }

        $window = self::stamp($now->modify(self::WINDOW));
        $cutoffs = $this->connection->fetchAllKeyValue(
            'SELECT forum_id, read_cutoff FROM forum_reads WHERE user_id = ? AND forum_id IN (?)',
            [$userId, array_values(array_unique(array_map(static fn(array $thread): int => $thread[0], $threads)))],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );
        $candidates = [];

        foreach ($threads as $threadId => [$forumId, $lastPost]) {
            $cutoff = isset($cutoffs[$forumId]) ? (string) $cutoffs[$forumId] : '';
            $floor = $cutoff > $window ? $cutoff : $window;

            if ($lastPost !== null && self::stamp($lastPost) > $floor) {
                $candidates[$threadId] = self::stamp($lastPost);
            }
        }

        if ($candidates === []) {
            return [];
        }

        $reads = $this->connection->fetchAllKeyValue(
            'SELECT thread_id, read_at FROM thread_reads WHERE user_id = ? AND thread_id IN (?)',
            [$userId, array_keys($candidates)],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );
        $unread = [];

        foreach ($candidates as $threadId => $lastPost) {
            if (!isset($reads[$threadId]) || (string) $reads[$threadId] < $lastPost) {
                $unread[] = $threadId;
            }
        }

        return $unread;
    }

    private static function stamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }
}
