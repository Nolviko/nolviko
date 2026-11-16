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
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Modules\Forums\Domain\ForumRuleViolation;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Threads\Domain\ThreadListItem;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Threads\Infrastructure\ThreadsTable;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Performance\BenchmarkHarness;
use Nolviko\Tests\Support\QueryCounter;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M03.02 on MySQL 8.4: thread lists in one query each, the M02.10 scope before LIMIT and COUNT, sticky
 * ordering, category and delete rules, and index use on a medium-sized generated data set (EXPLAIN).
 */
final class ThreadRepositoryTest extends TestCase
{
    private Connection $connection;

    private DbalForumRepository $forums;

    private DbalThreadRepository $threads;

    private int $alice;

    private int $bob;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->connection = self::connect();
        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        TestDatabase::reset($this->connection);
        $this->forums = new DbalForumRepository($this->connection);
        $this->threads = new DbalThreadRepository($this->connection);
        $users = new DbalUserRepository($this->connection);
        $this->alice = $users->create('alice', 'alice@example.com', 'h', UserStatus::Active)->id;
        $this->bob = $users->create('bob', 'bob@example.com', 'h', UserStatus::Active)->id;
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testCategoriesHoldNoThreadsAndForumsWithThreadsCannotBeDeleted(): void
    {
        $category = $this->forums->create(ForumType::Category, 'Cat', 'cat')->id;
        $forum = $this->forums->create(ForumType::Forum, 'Forum', 'forum', $category)->id;
        $this->threads->create($forum, $this->alice, 'Hello', 'hello', self::at(0));

        foreach ([[$category, 'thread_forum_is_category'], [999, 'thread_forum_not_found']] as [$target, $code]) {
            try {
                $this->threads->create($target, $this->alice, 'x', 'x', self::at(0));
                self::fail($code);
            } catch (ThreadRuleViolation $violation) {
                self::assertSame($code, $violation->diagnostic);
            }
        }

        try {
            $this->forums->delete($forum);
            self::fail('deleted a forum with threads');
        } catch (ForumRuleViolation $violation) {
            self::assertSame('forum_has_threads', $violation->diagnostic);
        }
    }

    public function testForumListPutsStickyFirstThenLatestActivityAndHidesInvisibleThreads(): void
    {
        $forum = $this->forums->create(ForumType::Forum, 'Forum', 'forum')->id;
        $old = $this->threads->create($forum, $this->alice, 'Old', 'old', self::at(10));
        $new = $this->threads->create($forum, $this->bob, 'New', 'new', self::at(20));
        $sticky = $this->threads->create($forum, $this->alice, 'Rules', 'rules', self::at(1));
        $this->threads->create($forum, $this->alice, 'Pending', 'pending', self::at(30), ThreadStatus::Pending);
        $this->threads->create($forum, $this->alice, 'Hidden', 'hidden', self::at(40), ThreadStatus::Hidden);
        $this->connection->update('threads', ['is_sticky' => 1], ['id' => $sticky]);

        $list = $this->threads->forumThreads(new ForumScope([$forum]), $forum, 10);

        self::assertSame([$sticky, $new, $old], self::ids($list));
        self::assertTrue($list[0]->sticky);
        self::assertSame(['bob', 'bob'], [$list[1]->authorName, $list[1]->lastPosterName]);
        self::assertSame([], $this->threads->forumThreads(new ForumScope([]), $forum, 10), 'forum not readable');
    }

    public function testHiddenForumThreadsNeverReachLatestOrCounts(): void
    {
        $open = $this->forums->create(ForumType::Forum, 'Open', 'open')->id;
        $staff = $this->forums->create(ForumType::Forum, 'Staff', 'staff')->id;
        $rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $rules->setGroupRule(BuiltInGroup::Guests->value, 'forum.view', false, $staff);

        foreach (range(1, 6) as $i) {
            $this->threads->create($open, $this->alice, 'Open ' . $i, 'open-' . $i, self::at($i));
            $this->threads->create($staff, $this->alice, 'Staff ' . $i, 'staff-' . $i, self::at($i + 100));
        }

        $scope = $this->readable($this->resolver()->guest());
        $latest = $this->threads->latest($scope, 3);

        self::assertSame([$open], array_values(array_unique(array_map(
            static fn(ThreadListItem $thread): int => $thread->forumId,
            $latest,
        ))), 'the newest staff threads are filtered before LIMIT');
        self::assertCount(3, $latest);
        self::assertSame(6, $this->threads->countVisible($scope));
        self::assertSame([], $this->threads->forumThreads($scope, $staff, 10));

        $member = $this->readable($this->resolver()->user($this->alice, [BuiltInGroup::Registered->value]));

        self::assertSame(12, $this->threads->countVisible($member), 'registered members see the staff forum');
    }

    public function testListsUseOneQueryAndShowRemovedAccounts(): void
    {
        $forum = $this->forums->create(ForumType::Forum, 'Forum', 'forum')->id;

        foreach (range(1, 40) as $i) {
            $author = $i % 2 === 0 ? $this->alice : $this->bob;
            $this->threads->create($forum, $author, 'T' . $i, 't-' . $i, self::at($i));
        }

        $this->connection->delete('users', ['id' => $this->bob]);
        $counter = new QueryCounter();
        $connection = self::connect([$counter]);
        $connection->fetchOne('SELECT 1');
        $counter->count = 0;
        $threads = new DbalThreadRepository($connection);

        $latest = $threads->latest(new ForumScope([$forum]), 40);
        $forumList = $threads->forumThreads(new ForumScope([$forum]), $forum, 40);

        self::assertSame(2, $counter->count, 'one query per list, whatever the number of rows');
        self::assertCount(40, $latest);
        self::assertCount(40, $forumList);
        self::assertSame([null, 'alice'], [$latest[1]->authorName, $latest[0]->authorName], 'bob was deleted');
    }

    public function testListQueriesUseTheirIndexesOnAMediumSizedBoard(): void
    {
        // Medium profile (docs/FIXTURES.md): 100 forums and 10,000 threads, generated here until the fixture
        // loader exists.
        $forumIds = [];

        foreach (range(1, 100) as $i) {
            $forumIds[] = $this->forums->create(ForumType::Forum, 'F' . $i, 'f-' . $i)->id;
        }

        $values = [];

        foreach (range(1, 10000) as $i) {
            $at = self::at($i)->format('Y-m-d H:i:s.u');
            $values[] = sprintf(
                "(%d, %d, 'T%d', 't-%d', '%s', %d, '%s', %d, '%s', '%s')",
                $forumIds[$i % 100],
                $i % 2 === 0 ? $this->alice : $this->bob,
                $i,
                $i,
                $i % 20 === 0 ? 'hidden' : 'visible',
                $i % 97 === 0 ? 1 : 0,
                $at,
                $this->alice,
                $at,
                $at,
            );

            if (count($values) === 1000) {
                $this->connection->executeStatement(
                    'INSERT INTO threads (forum_id, user_id, title, slug, status, is_sticky, last_post_at, '
                        . 'last_poster_id, created_at, updated_at) VALUES ' . implode(', ', $values),
                );
                $values = [];
            }
        }

        TestDatabase::analyze($this->connection, 'threads');
        $scope = new ForumScope(array_slice($forumIds, 0, 90));
        // EXPLAIN the statements the repository really sends, joins included: a plan check on a simplified
        // query once missed a hash join that sorted every matching thread.
        $counter = new QueryCounter();
        $recorded = new DbalThreadRepository(self::connect([$counter]));
        $recorded->forumThreads($scope, $forumIds[5], 20);
        $recorded->latest($scope, 20);
        [$forumSql, $latestSql] = array_slice($counter->statements, -2);
        $forumPlan = $this->explain($forumSql, [$forumIds[5], 'visible']);
        $latestPlan = $this->explain($latestSql, ['visible', ...$scope->forumIds]);
        // The first call right after the bulk insert is cold (statement prepare, buffer pool) and noisy on Docker;
        // the warm p50/p95 is the comparable number (PERFORMANCE_BUDGETS.md measurement protocol).
        $started = microtime(true);
        $latest = $this->threads->latest($scope, 20);
        $cold = (microtime(true) - $started) * 1000;
        $warm = BenchmarkHarness::measure(fn(): array => $this->threads->latest($scope, 20), 50, 5);

        // Plan shape is a MySQL-family budget; PostgreSQL's cost-based planner prefers a scan plus top-N sort
        // on tables this small, so there the latency budget below is the check (DATABASE_PORTABILITY.md).
        if (TestDatabase::driver() !== 'postgresql') {
            self::assertSame(ThreadsTable::FORUM_LIST_INDEX, $forumPlan['key'], json_encode($forumPlan) ?: '');
            self::assertStringNotContainsString('filesort', (string) $forumPlan['Extra']);
            self::assertSame(ThreadsTable::LATEST_INDEX, $latestPlan['key'], json_encode($latestPlan) ?: '');
            self::assertStringNotContainsString('filesort', (string) $latestPlan['Extra']);
        }
        self::assertCount(20, $latest);
        fwrite(STDERR, sprintf(
            "threads_latest_medium p50 %.2f ms  p95 %.2f ms  cold %.2f ms  rows %d (10000 threads, 90/100 forums)\n",
            $warm['p50_ms'],
            $warm['p95_ms'],
            $cold,
            (int) $latestPlan['rows'],
        ));
        self::assertLessThan(50.0, $warm['p95_ms']);
    }

    /**
     * @param list<int|string> $parameters
     *
     * @return array<string, mixed> The plan row for table `t`.
     */
    private function explain(string $sql, array $parameters): array
    {
        return TestDatabase::explainRow($this->connection, $sql, $parameters, 't');
    }

    private function readable(SubjectPermissions $subject): ForumScope
    {
        $storage = new TemporaryDirectory();
        $counter = new VersionCounter($this->connection);
        $visibility = new ForumVisibility(
            (new CacheFactory($storage->path))->namespace('permissions'),
            static fn(): int => $counter->current(VersionCounter::PERMISSION_RULES),
        );

        return $visibility->readable($subject);
    }

    private function resolver(): PermissionResolver
    {
        return new PermissionResolver(
            PermissionCatalog::core(),
            new DbalPermissionRuleStore($this->connection, PermissionCatalog::core()),
            new DbalForumTree($this->connection),
        );
    }

    /**
     * @param list<ThreadListItem> $threads
     *
     * @return list<int>
     */
    private static function ids(array $threads): array
    {
        return array_map(static fn(ThreadListItem $thread): int => $thread->id, $threads);
    }

    private static function at(int $minutes): DateTimeImmutable
    {
        $start = new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('UTC'));

        return $start->modify('+' . $minutes . ' minutes');
    }

    /**
     * @param list<QueryCounter> $middlewares
     */
    private static function connect(array $middlewares = []): Connection
    {
        return ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        ), $middlewares);
    }
}
