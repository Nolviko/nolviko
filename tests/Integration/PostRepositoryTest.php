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
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Posts\Domain\PostPreview;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Posts\Infrastructure\PostsTable;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Performance\BenchmarkHarness;
use Nolviko\Tests\Support\QueryCounter;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/**
 * M03.03 on MySQL 8.4: Unicode round trip, foreign keys and delete behaviour, revisions, and the M02.10 scope
 * on "latest posts" and thread previews.
 */
final class PostRepositoryTest extends TestCase
{
    private Connection $connection;

    private DbalPostRepository $posts;

    private DbalThreadRepository $threads;

    private int $alice;

    private int $open;

    private int $staff;

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
        $this->threads = new DbalThreadRepository($this->connection);
        $this->alice = (new DbalUserRepository($this->connection))
            ->create('alice', 'alice@example.com', 'h', UserStatus::Active)->id;
        $forums = new DbalForumRepository($this->connection);
        $this->open = $forums->create(ForumType::Forum, 'Open', 'open')->id;
        $this->staff = $forums->create(ForumType::Forum, 'Staff', 'staff')->id;
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testUnicodeRoundTripsByteForByte(): void
    {
        $texts = [
            '4-byte emoji with skin tone' => "Te\u{015F}ekk\u{00FC}rler \u{1F44D}\u{1F3FD} \u{1F600}",
            'ZWJ family and flag' => "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} \u{1F1F9}\u{1F1F7}",
            'Turkish letters' => "\u{0130}stanbul \u{0131}\u{015F}\u{0131}k \u{011F}\u{00FC}\u{00E7} "
                . "\u{00D6}\u{00C7}\u{015E}",
            'Arabic with marks' => "\u{0645}\u{064E}\u{0631}\u{0652}\u{062D}\u{064E}\u{0628}\u{064B}\u{0627} \u{200F}!",
            'mixed scripts and math' => "\u{05E9}\u{05DC}\u{05D5}\u{05DD} \u{2211} \u{1D400} "
                . "\u{0928}\u{092E}\u{0938}\u{094D}\u{0924}\u{0947}",
        ];
        $title = $texts['4-byte emoji with skin tone'];
        $thread = $this->threads->create($this->open, $this->alice, $title, 't', self::at(0));

        foreach ($texts as $name => $text) {
            $id = $this->posts->add($thread, $this->alice, $text, 'test', self::at(1));
            self::assertSame($text, $this->posts->find($id)?->content, $name);
        }

        self::assertSame(
            $texts['4-byte emoji with skin tone'],
            $this->connection->fetchOne('SELECT title FROM threads WHERE id = ?', [$thread]),
        );
        $postgres = TestDatabase::driver() === 'postgresql';
        self::assertSame(
            ['text' => $postgres ? 'text' : 'mediumtext'],
            ['text' => strtolower((string) $this->connection->fetchOne(
                'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '
                    . ($postgres ? 'current_schema()' : 'DATABASE()')
                    . " AND TABLE_NAME = 'posts' AND COLUMN_NAME = 'content'",
            ))],
            'LONG_TEXT maps to MEDIUMTEXT on MySQL and TEXT on PostgreSQL',
        );
    }

    public function testSummariesAreWordsNotMarkup(): void
    {
        $thread = $this->threads->create($this->open, $this->alice, 'T', 't', self::at(0));
        $this->posts->add($thread, $this->alice, "[b]Selam[/b] [url=https://x.test]bağlantı[/url]\n"
            . '[img]https://x.test/a.png[/img] [quote=bob]alıntı[/quote] son <söz>', 'bbcode', self::at(1));

        $summary = $this->posts->latestByUser(new ForumScope([$this->open]), $this->alice, 1)[0]->excerpt;

        // Tags go, their words stay; images and quoted text go; escaping is left to the template.
        self::assertSame('Selam bağlantı son <söz>', $summary);
    }

    public function testDeleteBehaviour(): void
    {
        $thread = $this->threads->create($this->open, $this->alice, 'T', 't', self::at(0));
        $post = $this->posts->add($thread, $this->alice, 'first', 'test', self::at(0));
        $this->connection->update('threads', ['first_post_id' => $post, 'last_post_id' => $post], ['id' => $thread]);
        $this->posts->edit($post, $this->alice, 'second', 'test', 'typo', self::at(5));

        $this->connection->delete('users', ['id' => $this->alice]);
        $orphan = $this->posts->find($post);

        self::assertNull($orphan?->userId, 'a deleted account leaves the post without author');
        self::assertNull($orphan?->editedBy);
        self::assertNull($this->posts->revisions($post)[0]['editor_id']);

        $this->connection->delete('posts', ['id' => $post]);

        self::assertSame([null, null], array_values((array) $this->connection->fetchAssociative(
            'SELECT first_post_id, last_post_id FROM threads WHERE id = ?',
            [$thread],
        )), 'thread references become NULL');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM post_revisions'), 'cascade');

        $this->posts->add($thread, null, 'reply', 'test', self::at(6));
        $this->connection->delete('threads', ['id' => $thread]);

        $remaining = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM posts');

        self::assertSame(0, $remaining, 'posts go with the thread');
    }

    public function testEditsKeepRevisionsAndInvalidateTheRendering(): void
    {
        $thread = $this->threads->create($this->open, $this->alice, 'T', 't', self::at(0));
        $post = $this->posts->add($thread, $this->alice, 'v1', 'test', self::at(0));
        $this->connection->update('posts', ['renderer_version' => 3], ['id' => $post]);

        self::assertTrue($this->posts->edit($post, $this->alice, 'v2', 'test', 'typo', self::at(1)));
        self::assertTrue($this->posts->edit($post, null, 'v3', 'other', null, self::at(2)));
        self::assertFalse($this->posts->edit(999, null, 'x', 'test', null, self::at(3)));

        $current = $this->posts->find($post);
        $revisions = $this->posts->revisions($post);

        self::assertSame(
            ['v3', 'other', null],
            [$current?->content, $current?->contentFormat, $current?->rendererVersion],
        );
        self::assertSame('2026-11-01 00:02:00', $current?->editedAt?->format('Y-m-d H:i:s'));
        self::assertSame(['v1', 'v2'], array_column($revisions, 'previous_content'));
        self::assertSame(['typo', null], array_column($revisions, 'change_reason'));
    }

    public function testLatestPostsAndPreviewsRespectTheScopeAndStatuses(): void
    {
        $open = $this->threads->create($this->open, $this->alice, 'Open thread', 'open', self::at(0));
        $staff = $this->threads->create($this->staff, $this->alice, 'Staff thread', 'staff', self::at(0));
        $hidden = $this->threads->create(
            $this->open,
            $this->alice,
            'Hidden',
            'hidden',
            self::at(0),
            ThreadStatus::Hidden,
        );
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        $first = $this->posts->add($open, $this->alice, str_repeat($family, 300), 'test', self::at(1));
        $this->connection->update('threads', ['first_post_id' => $first], ['id' => $open]);

        foreach (range(2, 6) as $i) {
            $this->posts->add($open, $this->alice, 'open ' . $i, 'test', self::at($i));
            $this->posts->add($staff, $this->alice, 'staff ' . $i, 'test', self::at($i + 10));
        }

        $this->posts->add($open, $this->alice, 'pending', 'test', self::at(30), PostStatus::Pending);
        $this->posts->add($hidden, $this->alice, 'in a hidden thread', 'test', self::at(31));
        $staffFirst = $this->posts->add($staff, $this->alice, 'staff first', 'test', self::at(0));
        $this->connection->update('threads', ['first_post_id' => $staffFirst], ['id' => $staff]);
        $scope = new ForumScope([$this->open]);

        $latest = $this->posts->latest($scope, 3);
        $previews = $this->posts->threadPreviews($scope, [$open, $staff, $hidden]);

        self::assertSame(['open 6', 'open 5', 'open 4'], array_map(
            static fn(PostPreview $preview): string => $preview->excerpt,
            $latest,
        ), 'newer staff, pending and hidden-thread posts are filtered before LIMIT');
        self::assertSame([$open], array_keys($previews), 'no preview of the staff forum or the hidden thread');
        self::assertSame(PostRepository::EXCERPT_LENGTH, grapheme_strlen($previews[$open]->excerpt));
        self::assertSame(str_repeat($family, 200), $previews[$open]->excerpt, 'cut between graphemes');
        self::assertSame([], $this->posts->latest(new ForumScope([]), 10));
    }

    public function testThreadPostsUseTheThreadIndex(): void
    {
        $thread = $this->threads->create($this->open, $this->alice, 'T', 't', self::at(0));

        foreach (range(1, 30) as $i) {
            $status = $i % 10 === 0 ? PostStatus::Hidden : PostStatus::Visible;
            $this->posts->add($thread, $this->alice, 'p' . $i, 'test', self::at($i), $status);
        }

        $page = $this->posts->threadPosts($thread, 5, 0);
        $next = $this->posts->threadPosts($thread, 5, $page[4]->id);
        $plan = $this->connection->fetchAssociative(
            'EXPLAIN SELECT id FROM posts WHERE thread_id = ? AND status = ? AND id > ? ORDER BY id LIMIT 5',
            [$thread, 'visible', 0],
        );

        self::assertSame(['p1', 'p2', 'p3', 'p4', 'p5'], array_map(static fn($post): string => $post->content, $page));
        self::assertSame(['p6', 'p7', 'p8', 'p9', 'p11'], array_map(static fn($post): string => $post->content, $next));
        $plan = TestDatabase::explainRow(
            $this->connection,
            'SELECT id FROM posts WHERE thread_id = ? AND status = ? AND id > ? ORDER BY id LIMIT 5',
            [$thread, 'visible', 0],
            'posts',
        );
        if (TestDatabase::driver() === 'postgresql') {
            // Any index walk; on eleven rows the planner may pick posts(status, id) instead of the thread index.
            self::assertNotNull($plan['key'] ?? null, (string) ($plan['plan'] ?? ''));
        } else {
            self::assertSame(PostsTable::THREAD_INDEX, $plan['key'] ?? null);
        }
    }

    /**
     * Benchmark case `posts_latest_medium` (100 forums, 2,000 threads, 20,000 posts; 5 % of the threads and
     * 10 % of the posts not visible): latest posts and the previews of one page of threads, with EXPLAIN on the
     * statements the repository really sends, joins included.
     */
    public function testListQueriesUseTheirIndexesOnAMediumSizedBoard(): void
    {
        $bob = (new DbalUserRepository($this->connection))
            ->create('bob', 'bob@example.com', 'h', UserStatus::Active)->id;
        $forums = new DbalForumRepository($this->connection);
        $forumIds = [];

        foreach (range(1, 100) as $i) {
            $forumIds[] = $forums->create(ForumType::Forum, 'F' . $i, 'f-' . $i)->id;
        }

        $this->bulk(
            'threads (forum_id, user_id, title, slug, status, created_at, updated_at)',
            range(1, 2000),
            fn(int $i): string => sprintf(
                "(%d, %d, 'T%d', 't-%d', '%s', '%s', '%s')",
                $forumIds[$i % 100],
                $this->alice,
                $i,
                $i,
                $i % 20 === 0 ? 'hidden' : 'visible',
                self::stamp($i),
                self::stamp($i),
            ),
        );
        $threadIds = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM threads ORDER BY id'));
        $this->bulk(
            'posts (thread_id, user_id, content, content_format, status, created_at, updated_at)',
            range(1, 20000),
            fn(int $i): string => sprintf(
                "(%d, %d, '%s', 'bbcode', '%s', '%s', '%s')",
                $threadIds[$i % 2000],
                $i % 2 === 0 ? $this->alice : $bob,
                str_repeat('Post text ' . $i . ' ', 20),
                $i % 10 === 0 ? 'hidden' : 'visible',
                self::stamp(2000 + $i),
                self::stamp(2000 + $i),
            ),
        );
        $this->connection->executeStatement(
            'UPDATE threads t SET first_post_id = (SELECT MIN(p.id) FROM posts p WHERE p.thread_id = t.id)',
        );
        TestDatabase::analyze($this->connection, 'threads, posts');
        $scope = new ForumScope(array_slice($forumIds, 0, 90));
        $page = array_slice($threadIds, 100, 20);
        $counter = new QueryCounter();
        $recorded = new DbalPostRepository(self::connect([$counter]));
        $latest = $recorded->latest($scope, 20);
        $previews = $recorded->threadPreviews($scope, $page);
        [$latestSql, $previewSql] = array_slice($counter->statements, -2);
        $latestPlan = $this->explain($latestSql, ['visible', 'visible', ...$scope->forumIds], 'p');
        $previewPlan = $this->explain($previewSql, [...$page, 'visible', 'visible', ...$scope->forumIds], 't');
        $warm = BenchmarkHarness::measure(fn(): array => $this->posts->latest($scope, 20), 50, 5);
        $warmPreviews = BenchmarkHarness::measure(fn(): array => $this->posts->threadPreviews($scope, $page), 50, 5);

        fwrite(STDERR, sprintf(
            "posts_latest_medium p50 %.2f ms  p95 %.2f ms  previews p50 %.2f ms  p95 %.2f ms (20,000 posts)\n",
            $warm['p50_ms'],
            $warm['p95_ms'],
            $warmPreviews['p50_ms'],
            $warmPreviews['p95_ms'],
        ));
        self::assertCount(20, $latest);
        self::assertNotSame([], $previews);
        // Newest first along the primary key or (M04.07) posts(status, id), stopping after the page: no full scan,
        // no sort of all posts.
        // Plan shape is a MySQL-family budget; PostgreSQL's cost-based planner prefers a scan plus top-N sort
        // on tables this small, so there the latency budget below is the check (DATABASE_PORTABILITY.md).
        if (TestDatabase::driver() !== 'postgresql') {
            self::assertContains($latestPlan['key'], ['PRIMARY', 'ix_posts_status'], json_encode($latestPlan) ?: '');
            self::assertStringNotContainsString('filesort', (string) $latestPlan['Extra']);
            self::assertSame('PRIMARY', $previewPlan['key'], json_encode($previewPlan) ?: '');
        }
        self::assertLessThan(50.0, $warm['p95_ms']);
    }

    /**
     * @param list<int|string> $parameters
     *
     * @return array<string, mixed> The first plan row for the table alias.
     */
    private function explain(string $sql, array $parameters, string $table): array
    {
        return TestDatabase::explainRow($this->connection, $sql, $parameters, $table);
    }

    /**
     * @param list<int> $numbers
     * @param callable(int): string $row One VALUES tuple per number.
     */
    private function bulk(string $target, array $numbers, callable $row): void
    {
        foreach (array_chunk($numbers, 1000) as $chunk) {
            $this->connection->executeStatement(
                'INSERT INTO ' . $target . ' VALUES ' . implode(', ', array_map($row, $chunk)),
            );
        }
    }

    private static function stamp(int $minutes): string
    {
        return self::at($minutes)->format('Y-m-d H:i:s.u');
    }

    /**
     * @param list<mixed> $middlewares
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

    private static function at(int $minutes): DateTimeImmutable
    {
        $start = new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('UTC'));

        return $start->modify('+' . $minutes . ' minutes');
    }
}
