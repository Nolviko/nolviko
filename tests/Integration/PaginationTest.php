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
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Domain\PagedPost;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\ThreadCursor;
use Nolviko\Modules\Threads\Http\ForumThreadsHandler;
use Nolviko\Modules\Threads\Http\PostPermalinkHandler;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Threads\Infrastructure\ThreadsTable;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Performance\BenchmarkHarness;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\QueryCounter;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M03.11 on MySQL 8.4 (ADR-014): forum thread lists page by keyset in both directions without gaps, repeats or
 * reordering, refuse foreign cursors, and use the list index; post permalinks resolve to the page that shows the
 * post, also after soft deletes, and hidden posts stay unreachable for members.
 */
final class PaginationTest extends TestCase
{
    private Connection $connection;

    private QueryCounter $counter;

    private DbalForumRepository $forums;

    private DbalThreadRepository $threads;

    private DbalPostRepository $posts;

    private DbalPermissionRuleStore $rules;

    private TemporaryDirectory $storage;

    private int $alice;

    private int $mod;

    private int $forum;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->counter = new QueryCounter();
        $this->connection = ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        ), [$this->counter]);
        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        TestDatabase::reset($this->connection);
        $this->forums = new DbalForumRepository($this->connection);
        $this->threads = new DbalThreadRepository($this->connection);
        $this->posts = new DbalPostRepository($this->connection);
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $this->storage = new TemporaryDirectory();
        $users = new DbalUserRepository($this->connection);
        $groups = new DbalUsergroupRepository($this->connection);
        $this->alice = $users->create('alice', 'alice@example.com', 'h', UserStatus::Active)->id;
        $this->mod = $users->create('mod', 'mod@example.com', 'h', UserStatus::Active)->id;
        $groups->addMember($this->alice, BuiltInGroup::Registered->value);
        $groups->addMember($this->mod, BuiltInGroup::Registered->value);
        $groups->addMember($this->mod, BuiltInGroup::Moderators->value);
        $this->forum = $this->forums->create(ForumType::Forum, 'General', 'general')->id;
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    /**
     * 70 threads, 4 sticky, many sharing a last-post time: walking older pages and then newer pages back again
     * visits every thread once, in exactly the SQL order, and the same request always gives the same page.
     */
    public function testKeysetWalksBothWaysWithoutGapsOrRepeats(): void
    {
        foreach (range(1, 70) as $i) {
            // Groups of five share a timestamp, so the ID tie-breaker decides inside each group.
            $at = self::at(intdiv($i, 5));
            $thread = $this->threads->create($this->forum, $this->alice, 'Thread ' . $i, 't', $at);

            if ($i % 17 === 0) {
                $this->connection->update('threads', ['is_sticky' => 1], ['id' => $thread]);
            }
        }

        $expected = $this->connection->fetchFirstColumn(
            'SELECT title FROM threads WHERE forum_id = ? ORDER BY is_sticky DESC, last_post_at DESC, id DESC',
            [$this->forum],
        );
        $pages = [];
        $url = '/forums/' . $this->forum;
        $last = $url;

        while ($url !== null) {
            $html = (string) $this->get($url, null)->getContent();
            $pages[] = self::titles($html);
            $last = $url;
            $url = self::link($html, 'next');
        }

        self::assertSame($expected, array_merge(...$pages));
        self::assertCount(3, $pages, '25 + 25 + 20');

        $back = [];
        $url = self::link((string) $this->get($last, null)->getContent(), 'prev');

        while ($url !== null) {
            $html = (string) $this->get($url, null)->getContent();
            array_unshift($back, self::titles($html));
            $url = self::link($html, 'prev');
        }

        self::assertSame(array_slice($pages, 0, 2), $back, 'newer pages rebuild the earlier pages exactly');

        $second = (string) self::link((string) $this->get('/forums/' . $this->forum, null)->getContent(), 'next');
        $html = (string) $this->get($second, null)->getContent();

        self::assertSame($html, (string) $this->get($second, null)->getContent(), 'identical requests');
        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $html);
    }

    public function testForeignCursorsCategoriesAndHiddenForumsAreNotFound(): void
    {
        $category = $this->forums->create(ForumType::Category, 'Cat', 'cat')->id;
        $staff = $this->forums->create(ForumType::Forum, 'Staff', 'staff')->id;
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.view', false, $staff);
        $base = '/forums/' . $this->forum;

        foreach (['?after=bogus', '?before=%2F%2F', '?after=MXwwfDIwMjYtMTMtNDAgMDA6MDA6MDAuMDAwMDAwfDE'] as $query) {
            self::assertSame(Response::HTTP_NOT_FOUND, $this->get($base . $query, null)->getStatusCode(), $query);
        }

        self::assertSame(Response::HTTP_NOT_FOUND, $this->get('/forums/' . $category, null)->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->get('/forums/' . $staff, $this->alice)->getStatusCode());
        self::assertStringContainsString(
            'There are no threads in this forum yet.',
            (string) $this->get($base, null)->getContent(),
        );
    }

    public function testForumPageListsReadableSubforumsParentsAndReplyCounts(): void
    {
        $category = $this->forums->create(ForumType::Category, 'Hall', 'hall')->id;
        $parent = $this->forums->create(ForumType::Forum, 'Parent', 'parent', $category)->id;
        $child = $this->forums->create(ForumType::Forum, 'Child', 'child', $parent)->id;
        $hidden = $this->forums->create(ForumType::Forum, 'Hidden', 'hidden', $parent)->id;
        $this->rules->setGroupRule(BuiltInGroup::Guests->value, 'forum.view', false, $hidden);
        $thread = $this->threads->create($parent, $this->alice, 'Counted', 'counted', self::at(1));
        $this->connection->update('threads', ['reply_count' => 7], ['id' => $thread]);

        $html = (string) $this->get('/forums/' . $parent, null)->getContent();

        self::assertStringContainsString('<span><bdi>Hall</bdi></span>', $html);
        self::assertStringContainsString('<a href="/forums/' . $child . '"><bdi>Child</bdi></a>', $html);
        self::assertStringNotContainsString('Hidden', $html);
        self::assertMatchesRegularExpression('~<div class="stat"><b>7</b><span>replies</span></div>~', $html);
    }

    /**
     * Four forums with 1,500 threads each: a deep cursor page of a forum reads the list index in order.
     */
    public function testTheSeekQueryUsesTheListIndex(): void
    {
        $forums = [$this->forum];

        foreach (range(2, 4) as $n) {
            $forums[] = $this->forums->create(ForumType::Forum, 'F' . $n, 'f-' . $n)->id;
        }

        $values = [];

        foreach (range(1, 6000) as $i) {
            $at = self::at($i)->format('Y-m-d H:i:s.u');
            $values[] = sprintf("(%d, 'T%d', 't', 'visible', '%s', '%s', '%s')", $forums[$i % 4], $i, $at, $at, $at);
        }

        foreach (array_chunk($values, 1000) as $chunk) {
            $this->connection->executeStatement(
                'INSERT INTO threads (forum_id, title, slug, status, last_post_at, created_at, updated_at) VALUES '
                    . implode(', ', $chunk),
            );
        }

        TestDatabase::analyze($this->connection, 'threads');
        $first = $this->threads->forumThreads(new ForumScope([$this->forum]), $this->forum, 26);
        $cursor = ThreadCursor::of($first[24]);
        self::assertNotNull($cursor);
        $this->counter->statements = [];
        $this->threads->forumThreads(new ForumScope([$this->forum]), $this->forum, 26, after: $cursor);
        $sql = (string) end($this->counter->statements);
        $plan = null;
        $parameters = [
            $this->forum,
            'visible',
            0,
            $cursor->timestamp(),
            $cursor->timestamp(),
            $cursor->threadId,
        ];
        $plan = TestDatabase::explainRow(
            $this->connection,
            $sql,
            $parameters,
            't',
            DbalThreadRepository::types($parameters),
        );

        self::assertIsArray($plan);
        self::assertSame(ThreadsTable::FORUM_LIST_INDEX, $plan['key'], json_encode($plan) ?: '');

        // No sort at all is a MySQL-family budget; PostgreSQL walks the same index and may add an incremental
        // sort on the small test forum, so there the latency budget below is the check.
        if (TestDatabase::driver() !== 'postgresql') {
            self::assertStringNotContainsString('filesort', (string) $plan['Extra']);
        }

        // Benchmark case `forum_threads_cursor`: a page deep in the forum (cursor at the 1,000th thread).
        $deep = ThreadCursor::of($this->threads->forumThreads(new ForumScope([$this->forum]), $this->forum, 100)[99]);
        $scope = new ForumScope([$this->forum]);
        $result = BenchmarkHarness::measure(
            fn(): array => $this->threads->forumThreads($scope, $this->forum, 26, after: $deep),
            50,
            5,
        );
        fwrite(STDERR, sprintf(
            "forum_threads_cursor p50 %.2f ms  p95 %.2f ms (1,500 threads in the forum, 6,000 in all)
",
            $result['p50_ms'],
            $result['p95_ms'],
        ));
    }

    /**
     * 45 visible posts with hidden ones in between, pages of 10: each permalink lands on the page that shows the
     * post, before and after soft-deleting early posts; hidden posts are 404 for members and reach moderators on
     * the page where they are shown.
     */
    public function testPermalinksResolveToThePageThatShowsThePost(): void
    {
        $thread = $this->threads->create($this->forum, $this->alice, 'Long', 'long', self::at(0));
        $ids = [];

        foreach (range(1, 52) as $i) {
            $status = $i > 1 && $i % 7 === 0 ? PostStatus::Hidden : PostStatus::Visible;
            $ids[$i] = $this->posts->add($thread, $this->alice, 'P' . $i, 'bbcode', self::at($i), $status);
        }

        $tail = $this->posts->add($thread, $this->alice, 'Tail', 'bbcode', self::at(60), PostStatus::Hidden);
        $this->syncThread($thread, $ids[1]);
        $this->assertPermalinksMatchPages($thread);

        foreach ([2, 3, 12] as $i) {
            $this->connection->update('posts', ['status' => 'hidden'], ['id' => $ids[$i]]);
        }

        $this->syncThread($thread, $ids[1]);
        $this->assertPermalinksMatchPages($thread);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->get('/posts/' . $ids[7], $this->alice)->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->get('/posts/999999', $this->alice)->getStatusCode());
        $location = (string) $this->get('/posts/' . $tail, $this->mod)->headers->get('Location');
        self::assertSame(
            $this->base($thread) . '/page-5#post-' . $tail,
            $location,
            'after the last visible post',
        );
    }

    /** The canonical `/threads/{slug}-{id}` address of a thread. */
    private function base(int $thread): string
    {
        return '/threads/' . $this->connection->fetchOne('SELECT slug FROM threads WHERE id = ?', [$thread])
            . '-' . $thread;
    }

    private function assertPermalinksMatchPages(int $thread): void
    {
        $total = (int) $this->connection->fetchOne('SELECT reply_count FROM threads WHERE id = ?', [$thread]) + 1;
        $pages = intdiv($total + 9, 10);
        $shownOn = [];

        foreach (range(1, $pages) as $page) {
            foreach ($this->posts->threadPage($thread, $page, 10, $total, true) as $paged) {
                /** @var PagedPost $paged */
                $shownOn[$paged->post->id] = $page;
            }
        }

        foreach ($shownOn as $postId => $page) {
            $visible = $this->posts->find($postId)?->status === PostStatus::Visible;
            $response = $this->get('/posts/' . $postId, $visible ? $this->alice : $this->mod);
            $expected = ($page === 1 ? $this->base($thread) : $this->base($thread) . '/page-' . $page)
                . '#post-' . $postId;

            self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
            self::assertSame($expected, $response->headers->get('Location'), 'post ' . $postId);
        }
    }

    private function syncThread(int $thread, int $firstPostId): void
    {
        $this->connection->executeStatement(
            "UPDATE threads SET first_post_id = ?, reply_count = (SELECT COUNT(*) - 1 FROM posts WHERE thread_id = ? "
                . "AND status = 'visible'), last_post_id = (SELECT MAX(id) FROM posts WHERE thread_id = ? "
                . "AND status = 'visible') WHERE id = ?",
            [$firstPostId, $thread, $thread, $thread],
        );
    }

    private function get(string $url, ?int $userId): Response
    {
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $session = new ArraySession();

        if ($userId !== null) {
            $session->set('_user_id', $userId);
        }

        $csrf = new CsrfTokens($session);
        $subjects = new RequestSubject(
            new AuthSession($session, $csrf, new MemoryUsers(), new ResponseCookies()),
            new DbalUsergroupRepository($this->connection),
            $this->resolver(),
        );
        $router = new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
        $request = Request::create($url);
        $match = $router->match($request);
        $parameters = array_filter(
            $match,
            static fn(string $key): bool => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );
        $handler = $match['_route'] === CoreRoutes::POST_SHOW
            ? new PostPermalinkHandler(
                $subjects,
                $this->threads,
                $this->posts,
                $this->visibility(),
                $router,
                new ErrorResponder($translator),
                10,
            )
            : new ForumThreadsHandler(
                $translator,
                TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-no-storage', $translator),
                $subjects,
                $this->forums,
                $this->threads,
                $this->visibility(),
                $router,
                new ErrorResponder($translator),
            );

        return $handler->handle($request, $parameters);
    }

    /**
     * @return list<string>
     */
    private static function titles(string $html): array
    {
        preg_match_all(
            '~<li class="trow thread[^"]*">.*?'
                . '<div class="trow-title">\s*(?:<span class="dot"[^>]*></span>\s*)?'
                . '<a href="[^"]*">(?:<bdi>)?([^<]*)(?:</bdi>)?</a>~s',
            $html,
            $matches,
        );

        return $matches[1];
    }

    private static function link(string $html, string $rel): ?string
    {
        return preg_match('~<a rel="' . $rel . '" href="([^"]+)"~', $html, $match) === 1
            ? html_entity_decode($match[1])
            : null;
    }

    private function resolver(): PermissionResolver
    {
        return new PermissionResolver(
            PermissionCatalog::core(),
            $this->rules,
            new DbalForumTree($this->connection),
        );
    }

    private function visibility(): ForumVisibility
    {
        $cache = (new CacheFactory($this->storage->path))->namespace('permissions');
        $counter = new VersionCounter($this->connection);

        return new ForumVisibility($cache, static fn(): int => $counter->current(VersionCounter::PERMISSION_RULES));
    }

    private static function at(int $minutes): DateTimeImmutable
    {
        return (new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('UTC')))
            ->modify('+' . $minutes . ' minutes');
    }
}
