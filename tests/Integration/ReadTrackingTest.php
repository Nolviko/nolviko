<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Threads\Infrastructure\DbalReadTracker;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\QueryCounter;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M03.12: a thread is unread until the member has seen its newest post; reading never moves backwards; "mark
 * forum read" covers every thread there and removes the per-thread rows; threads quiet for 30 days are read
 * without a row; a forum page costs two lookups.
 */
final class ReadTrackingTest extends TestCase
{
    private Connection $connection;

    private DbalReadTracker $reads;

    private DateTimeImmutable $now;

    private int $alice;

    private int $bob;

    private int $forum;

    /** @var array<string, int> */
    private array $threads = [];

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->connection = ConnectionFactory::create(self::config());

        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        TestDatabase::reset($this->connection);
        $users = new DbalUserRepository($this->connection);
        $this->alice = $users->create('Alice', 'alice@example.com', 'h', UserStatus::Active)->id;
        $this->bob = $users->create('Bob', 'bob@example.com', 'h', UserStatus::Active)->id;
        $this->forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'F', 'f')->id;
        $this->now = new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('UTC'));

        foreach (['fresh' => '-1 hour', 'yesterday' => '-1 day', 'old' => '-40 days'] as $name => $age) {
            $this->connection->insert('threads', [
                'forum_id' => $this->forum,
                'title' => $name,
                'slug' => $name,
                'status' => 'visible',
                'last_post_at' => $this->now->modify($age)->format('Y-m-d H:i:s.u'),
                'created_at' => $this->now->modify('-50 days')->format('Y-m-d H:i:s.u'),
                'updated_at' => $this->now->format('Y-m-d H:i:s.u'),
            ]);
            $this->threads[$name] = (int) $this->connection->lastInsertId();
        }

        $this->reads = new DbalReadTracker($this->connection);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testUnreadUntilTheNewestPostIsSeen(): void
    {
        self::assertSame([$this->threads['fresh'], $this->threads['yesterday']], $this->unread($this->alice));

        $this->reads->markThread($this->alice, $this->threads['fresh'], 10, $this->now->modify('-1 hour'));
        self::assertSame([$this->threads['yesterday']], $this->unread($this->alice));
        self::assertCount(2, $this->unread($this->bob), 'read state is per member');

        $this->reads->markThread($this->alice, $this->threads['fresh'], 3, $this->now->modify('-3 hours'));
        self::assertSame([$this->threads['yesterday']], $this->unread($this->alice), 'never moves backwards');
        self::assertSame(
            10,
            (int) $this->connection->fetchOne('SELECT last_read_post_id FROM thread_reads WHERE thread_id = ?', [
                $this->threads['fresh'],
            ]),
        );

        $this->connection->update('threads', [
            'last_post_at' => $this->now->modify('-10 minutes')->format('Y-m-d H:i:s.u'),
        ], ['id' => $this->threads['fresh']]);
        self::assertSame([$this->threads['fresh'], $this->threads['yesterday']], $this->unread($this->alice));
    }

    public function testMarkingTheForumReadReplacesThreadRows(): void
    {
        $this->reads->markThread($this->alice, $this->threads['yesterday'], 5, $this->now->modify('-1 day'));
        $this->reads->markForum($this->alice, $this->forum, $this->now);

        self::assertSame([], $this->unread($this->alice));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM thread_reads'));
        self::assertCount(2, $this->unread($this->bob));

        $this->reads->markForum($this->alice, $this->forum, $this->now->modify('-1 day'));
        self::assertSame([], $this->unread($this->alice), 'an older mark does not undo a newer one');

        $this->connection->update('threads', [
            'last_post_at' => $this->now->modify('+5 minutes')->format('Y-m-d H:i:s.u'),
        ], ['id' => $this->threads['yesterday']]);
        self::assertSame([$this->threads['yesterday']], $this->unread($this->alice, $this->now->modify('+10 minutes')));
    }

    public function testAForumPageCostsTwoLookups(): void
    {
        $counter = new QueryCounter();
        $reads = new DbalReadTracker(ConnectionFactory::create(self::config(), [$counter]));
        $reads->unread($this->alice, $this->forum, $this->lastPosts(), $this->now); // connects (session setup)
        $counter->count = 0;
        $reads->unread($this->alice, $this->forum, $this->lastPosts(), $this->now);

        self::assertSame(2, $counter->count);
    }

    /**
     * @return list<int>
     */
    private function unread(int $userId, ?DateTimeImmutable $now = null): array
    {
        $unread = $this->reads->unread($userId, $this->forum, $this->lastPosts(), $now ?? $this->now);
        sort($unread);

        return $unread;
    }

    /**
     * @return array<int, DateTimeImmutable>
     */
    private function lastPosts(): array
    {
        $lastPosts = [];

        foreach ($this->connection->fetchAllKeyValue('SELECT id, last_post_at FROM threads') as $id => $at) {
            $lastPosts[(int) $id] = new DateTimeImmutable((string) $at, new DateTimeZone('UTC'));
        }

        return $lastPosts;
    }

    private static function config(): Config
    {
        return new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        );
    }
}
