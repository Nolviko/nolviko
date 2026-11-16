<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Core\Events\Outbox;
use Nolviko\Core\RateLimit\ClientNetwork;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\CreateReply;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\NewReply;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Infrastructure\DbalReplyWriter;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadWriter;
use Nolviko\Modules\Threads\Infrastructure\PostingLock;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * M03.15 on MySQL 8.4: per-user and per-network posting intervals stored in `rate_limit_buckets`, the window
 * reset, the per-group exemption of moderators, and a rolled-back post that leaves no hit behind.
 */
final class RateLimitTest extends TestCase
{
    private Connection $connection;

    private DbalPostRepository $posts;

    private DbalPermissionRuleStore $rules;

    private TemporaryDirectory $storage;

    /** @var array<string, int> */
    private array $users = [];

    private int $forum;

    private int $thread;

    private DateTimeImmutable $start;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->connection = ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        ));
        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        TestDatabase::reset($this->connection);
        $this->posts = new DbalPostRepository($this->connection);
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $this->storage = new TemporaryDirectory();
        $accounts = new DbalUserRepository($this->connection);

        foreach (['alice', 'bob', 'mod'] as $name) {
            $this->users[$name] = $accounts->create($name, $name . '@example.com', 'h', UserStatus::Active)->id;
        }

        $this->forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'F', 'f')->id;
        $this->start = new DateTimeImmutable('2026-11-01 12:00:00', new DateTimeZone('UTC'));
        // The starter is outside the 30-second flood window but inside the one-hour cleanup grace.
        $this->thread = $this->threads($this->start->modify('-1 minute'))
            ->create($this->as('bob'), $this->forum, 'Topic', 'First post')->threadId;
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testCleanupPreservesPreparedBucketsAndAvoidsPostingTransactions(): void
    {
        $buckets = new RateBuckets($this->connection);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $buckets->ensure('prepared');
        $this->connection->insert('rate_limit_buckets', [
            'bucket_key' => 'expired',
            'last_hit_at' => '2000-01-01 00:00:00.000000',
            'expires_at' => '2000-01-01 00:00:00.000000',
        ]);
        $this->connection->beginTransaction();

        try {
            $buckets->prune($now);
            self::assertNotFalse($this->connection->fetchOne(
                'SELECT 1 FROM rate_limit_buckets WHERE bucket_key = ?',
                ['expired'],
            ));
        } finally {
            $this->connection->rollBack();
        }

        $buckets->prune($now);
        self::assertFalse($this->connection->fetchOne(
            'SELECT 1 FROM rate_limit_buckets WHERE bucket_key = ?',
            ['expired'],
        ));
        self::assertSame(0, $buckets->hit('prepared', 30, $now));
        self::assertGreaterThan(0, $buckets->hit('prepared', 30, $now));
    }

    public function testRetentionUsesTheSameClockAsTheHit(): void
    {
        $buckets = new RateBuckets($this->connection);
        $buckets->ensure('historical', $this->start);
        self::assertSame(
            $this->start->modify('+1 hour')->format('Y-m-d H:i:s.u'),
            $this->connection->fetchOne(
                'SELECT expires_at FROM rate_limit_buckets WHERE bucket_key = ?',
                ['historical'],
            ),
        );
        self::assertSame(0, $buckets->hit('historical', 30, $this->start));
        $buckets->ensure('other', $this->start->modify('+20 seconds'));
        $buckets->prune($this->start->modify('+20 seconds'));
        self::assertGreaterThan(0, $buckets->hit('historical', 30, $this->start->modify('+20 seconds')));
    }

    public function testPreparingAnExpiredExistingBucketProtectsItFromInterveningCleanup(): void
    {
        $buckets = new RateBuckets($this->connection);
        $old = $this->start->modify('-2 hours');
        $buckets->ensure('expired-existing', $old);
        self::assertSame(0, $buckets->hit('expired-existing', 30, $old));
        $buckets->ensure('expired-existing', $this->start);
        $buckets->prune($this->start);
        self::assertSame(0, $buckets->hit('expired-existing', 30, $this->start));
        $buckets->ensure('expired-existing', $this->start->modify('+1 second'));
        $buckets->prune($this->start->modify('+1 second'));
        self::assertSame(29, $buckets->hit('expired-existing', 30, $this->start->modify('+1 second')));
    }

    public function testPreparationCannotChangePostingLockOrderInsideATransaction(): void
    {
        $this->connection->beginTransaction();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rate_bucket_prepare_in_transaction');

        try {
            (new RateBuckets($this->connection))->ensure('nested', $this->start);
        } finally {
            $this->connection->rollBack();
        }
    }

    public function testMissingBucketCannotBypassTheLimit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rate_bucket_missing');
        (new RateBuckets($this->connection))->hit('not-prepared', 30, $this->start);
    }

    public function testTheUserIntervalAndItsWindowReset(): void
    {
        self::assertNull($this->reply('alice', 0, '192.0.2.1'));
        self::assertSame(['seconds' => 1], $this->reply('alice', 29, '192.0.2.2'), 'another address, same user');
        self::assertNull($this->reply('alice', 30, '192.0.2.3'), 'the window has passed');
        // Bob's day-old bucket may already be pruned (RateBuckets prunes expired rows at random), so only
        // Alice's row is certain.
        self::assertContains(
            PostingLock::USER_BUCKET . $this->users['alice'],
            $this->connection->fetchFirstColumn(
                "SELECT bucket_key FROM rate_limit_buckets WHERE bucket_key LIKE 'post.user:%' ORDER BY bucket_key",
            ),
        );
    }

    public function testTheNetworkIntervalCoversDifferentUsers(): void
    {
        self::assertNull($this->reply('alice', 0, '198.51.100.7'));
        self::assertSame(['seconds' => 10], $this->reply('bob', 5, '198.51.100.7'), 'same address, other user');
        self::assertNull($this->reply('bob', 6, '198.51.100.8'), 'another address');
        self::assertNull($this->reply('alice', 40, '2001:db8:aa:bb::1'));
        self::assertSame(['seconds' => 14], $this->reply('bob', 41, '2001:db8:aa:bb::2'), 'the same IPv6 /64');
        self::assertSame(
            1,
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM rate_limit_buckets WHERE bucket_key = ?', [
                PostingLock::NETWORK_BUCKET . ClientNetwork::key('198.51.100.7'),
            ]),
        );
        self::assertSame(0, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM rate_limit_buckets WHERE bucket_key LIKE '%198.51%'",
        ), 'no address is stored');
    }

    public function testModeratorsAreExemptPerGroup(): void
    {
        self::assertSame('0', $this->connection->fetchOne(
            'SELECT value FROM usergroup_permissions WHERE usergroup_id = ? AND permission_key = ?',
            [BuiltInGroup::Moderators->value, CreateThread::FLOOD],
        ), 'seeded by migration 0018');

        foreach (range(1, 3) as $i) {
            self::assertNull($this->reply('mod', 0, '203.0.113.9'), 'reply ' . $i);
        }

        // Without the exemption the groups combine to the most generous (lowest) value: registered's 30.
        $this->rules->setGroupRule(BuiltInGroup::Moderators->value, CreateThread::FLOOD, 60);
        self::assertNull($this->reply('mod', 0, '203.0.113.10'));
        self::assertSame(['seconds' => 29], $this->reply('mod', 1, '203.0.113.11'), 'exemption removed');
    }

    public function testARolledBackPostLeavesNoHit(): void
    {
        $this->connection->update('threads', ['is_locked' => 1], ['id' => $this->thread]);
        $writer = new DbalReplyWriter(
            $this->connection,
            new Outbox($this->connection),
            new PostingLock($this->connection),
        );

        try {
            $writer->reply(new NewReply(
                $this->thread,
                $this->forum,
                $this->users['alice'],
                CreateReply::newToken(),
                'Late',
                'bbcode',
                'Late',
                BbcodeRenderer::VERSION,
                PostStatus::Visible,
                $this->start,
                30,
                false,
                ClientNetwork::key('192.0.2.50'),
                15,
            ));
            self::fail('the locked thread accepted a reply');
        } catch (ThreadRuleViolation $violation) {
            self::assertSame('thread_locked', $violation->diagnostic);
        }

        $hits = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM rate_limit_buckets WHERE last_hit_at > '1970-01-01 00:00:00'",
        );

        self::assertSame(1, $hits, 'only the thread starter has a hit; the prepared buckets stay unused');
    }

    /**
     * @return array<string, int|string>|null Null when accepted, otherwise the violation's parameters.
     */
    private function reply(string $user, int $seconds, ?string $clientIp): ?array
    {
        $at = $this->start->modify('+' . $seconds . ' seconds');
        $replies = new CreateReply(
            new DbalThreadRepository($this->connection),
            $this->visibility(),
            $this->posts,
            new DbalReplyWriter(
                $this->connection,
                new Outbox($this->connection),
                new PostingLock($this->connection),
            ),
            clock: static fn(): DateTimeImmutable => $at,
        );

        try {
            $replies->reply($this->as($user), $this->thread, CreateReply::newToken(), 'Hi', $clientIp);
        } catch (ThreadRuleViolation $violation) {
            self::assertSame('post_flood_wait', $violation->diagnostic);

            return $violation->parameters;
        }

        return null;
    }

    private function threads(DateTimeImmutable $at): CreateThread
    {
        return new CreateThread(
            new DbalForumRepository($this->connection),
            $this->visibility(),
            $this->posts,
            new DbalThreadWriter(
                $this->connection,
                new DbalThreadRepository($this->connection),
                new Outbox($this->connection),
                new PostingLock($this->connection),
            ),
            clock: static fn(): DateTimeImmutable => $at,
        );
    }

    private function as(string $user): SubjectPermissions
    {
        $groups = [BuiltInGroup::Registered->value];

        if ($user === 'mod') {
            $groups[] = BuiltInGroup::Moderators->value;
        }

        return (new PermissionResolver(
            PermissionCatalog::core(),
            $this->rules,
            new DbalForumTree($this->connection),
        ))->user($this->users[$user], $groups);
    }

    private function visibility(): ForumVisibility
    {
        $cache = (new CacheFactory($this->storage->path))->namespace('permissions');
        $counter = new VersionCounter($this->connection);

        return new ForumVisibility($cache, static fn(): int => $counter->current(VersionCounter::PERMISSION_RULES));
    }
}
