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
use Nolviko\Core\Events\Outbox;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Settings\SiteSettings;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Http\DiscussionFeed;
use Nolviko\Modules\Forums\Http\ForumIndexHandler;
use Nolviko\Modules\Forums\Infrastructure\DbalForumIndex;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Infrastructure\DbalReadTracker;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadWriter;
use Nolviko\Modules\Threads\Infrastructure\PostingLock;
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
 * M03.09 on MySQL 8.4: the board index renders categories and forums in tree order with counters and last
 * post, leaves hidden forums out entirely, shows no last post where the reader may not read threads, has a
 * translated empty state, and uses the same number of queries for 4 or 110 forums.
 */
final class ForumIndexTest extends TestCase
{
    private Connection $connection;

    private QueryCounter $counter;

    private DbalForumRepository $forums;

    private DbalPermissionRuleStore $rules;

    private TemporaryDirectory $storage;

    private int $alice;

    private int $minute = 0;

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
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $this->storage = new TemporaryDirectory();
        $this->alice = (new DbalUserRepository($this->connection))
            ->create('alice', 'alice@example.com', 'h', UserStatus::Active)->id;
        (new DbalUsergroupRepository($this->connection))->addMember($this->alice, BuiltInGroup::Registered->value);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testTreeOrderCountersLastPostAndHiddenForums(): void
    {
        $general = $this->forums->create(ForumType::Category, 'General', 'general', sortOrder: 1)->id;
        $staff = $this->forums->create(ForumType::Category, 'Staff', 'staff', sortOrder: 2)->id;
        $news = $this->forums->create(ForumType::Forum, 'News', 'news', $general, 2)->id;
        $chat = $this->forums->create(ForumType::Forum, 'Chat <b>', 'chat', $general, 1)->id;
        $secret = $this->forums->create(ForumType::Forum, 'Secret plans', 'secret', $staff)->id;
        $this->thread($chat, 'Hello');
        $this->thread($chat, 'Second <i>');
        $this->thread($news, 'Release notes');
        $this->thread($secret, 'Do not show');
        $this->rules->setGroupRule(BuiltInGroup::Guests->value, 'forum.view', false, $staff);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.view', false, $staff);
        $this->rules->setGroupRule(BuiltInGroup::Guests->value, 'forum.thread.view', false, $news);

        $guest = (string) $this->render(null)->getContent();
        $member = (string) $this->render($this->alice)->getContent();

        self::assertSame(['General', 'Chat &lt;b&gt;', 'News'], self::titles($guest), 'tree order, staff hidden');
        self::assertStringNotContainsString('Secret', $guest . $member);
        self::assertStringNotContainsString('Do not show', $guest . $member);
        self::assertStringContainsString('2 threads · 2 posts', $guest);
        self::assertStringContainsString('Last post in “Second &lt;i&gt;” by alice', $guest);
        self::assertStringNotContainsString('Release notes', $guest, 'no last post where threads are unreadable');
        self::assertStringContainsString('Release notes', $member);
        self::assertStringNotContainsString('New thread', $guest);
        self::assertSame(2, substr_count($member, '>New thread</a>'), 'members may start threads in both forums');
        self::assertStringContainsString('/forums/' . $chat . '/new-thread', $member);
    }

    public function testDiscussionsLayoutListsLatestReadableThreadsWithShowMore(): void
    {
        $connection = $this->connection;
        $settings = new SiteSettings(
            static fn(): Connection => $connection,
            (new CacheFactory($this->storage->path))->namespace('settings'),
        );
        $settings->save(['home.layout' => 'discussions'], [], $this->alice);
        $general = $this->forums->create(ForumType::Category, 'General', 'general')->id;
        $open = $this->forums->create(ForumType::Forum, 'Open talk', 'open', $general)->id;
        $secret = $this->forums->create(ForumType::Forum, 'Secret plans', 'secret')->id;

        foreach (range(1, 27) as $i) {
            $this->thread($open, 'Talk ' . $i);
        }

        $this->thread($secret, 'Do not show');
        $this->rules->setGroupRule(BuiltInGroup::Guests->value, 'forum.view', false, $secret);
        $handler = $this->handler(null, settings: $settings);

        $first = (string) $handler->handle(Request::create('/'), [])->getContent();
        self::assertStringContainsString('Latest discussions', $first);
        self::assertSame(25, substr_count($first, '<li class="trow">'));
        self::assertStringContainsString('<bdi>Talk 27</bdi>', $first, 'newest activity first');
        self::assertStringNotContainsString('<bdi>Talk 2</bdi>', $first);
        self::assertStringNotContainsString('Secret', $first, 'hidden forums never appear, not even in the list');
        self::assertStringNotContainsString('Do not show', $first);
        self::assertStringContainsString('href="/forums/' . $open . '"', $first, 'forum label and sidebar link');
        self::assertStringNotContainsString('noindex, follow', $first);
        self::assertSame(1, preg_match('~href="(/\?after=[A-Za-z0-9_-]+)"~', $first, $more), 'show more link');

        $next = (string) $handler->handle(Request::create($more[1]), [])->getContent();
        self::assertSame(2, substr_count($next, '<li class="trow">'));
        self::assertStringContainsString('<bdi>Talk 2</bdi>', $next);
        self::assertStringContainsString('<bdi>Talk 1</bdi>', $next);
        self::assertStringContainsString('<meta name="robots" content="noindex, follow">', $next);
        self::assertStringNotContainsString('Show more', $next);
        self::assertSame(404, $handler->handle(Request::create('/?after=bad!'), [])->getStatusCode());

        $member = $this->handler($this->alice, settings: $settings);
        self::assertSame(25, substr_count(
            (string) $member->handle(Request::create('/'), [])->getContent(),
            '<li class="trow t-unread">',
        ), 'a member sees new threads as unread');
        (new DbalReadTracker($this->connection))->markForum(
            $this->alice,
            $open,
            new DateTimeImmutable('2026-11-01 08:27:30', new DateTimeZone('UTC')),
        );
        $this->thread($open, 'Fresh');
        $marked = (string) $member->handle(Request::create('/'), [])->getContent();
        self::assertSame(2, substr_count($marked, '<li class="trow t-unread">'), 'only Fresh and the staff thread');
        self::assertSame(1, preg_match('~t-unread">.*?<bdi>Fresh</bdi>~s', $marked));
        self::assertStringNotContainsString('t-unread', $first, 'guests have no read state');

        $settings->save(['home.layout' => 'classic'], [], $this->alice);
        self::assertSame(['General', 'Open talk'], self::titles(
            (string) $handler->handle(Request::create('/'), [])->getContent(),
        ), 'classic layout is the board index again');
        // Other suites refuse a database whose `settings` table holds rows (an installed forum).
        $this->connection->executeStatement('DELETE FROM settings');
    }

    public function testSubforumsAreLinksUnderTheirForumNotRows(): void
    {
        $general = $this->forums->create(ForumType::Category, 'General', 'general')->id;
        $games = $this->forums->create(ForumType::Forum, 'Games', 'games', $general)->id;
        $this->forums->create(ForumType::Forum, 'Strategy', 'strategy', $games);
        $html = (string) $this->render(null)->getContent();

        self::assertSame(['General', 'Games'], self::titles($html), 'the subforum is not a row');
        self::assertStringContainsString('Subforums:', $html);
        self::assertMatchesRegularExpression(
            '~Subforums:</span> <a href="/forums/\d+"><bdi>Strategy</bdi></a>~',
            $html,
        );
    }

    public function testEmptyBoardShowsTheTranslatedEmptyState(): void
    {
        $response = $this->render(null);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('There are no forums to show yet.', (string) $response->getContent());
        self::assertSame('no-cache, private', $response->headers->get('Cache-Control'));
    }

    /**
     * The query count of a warm request (visibility scope cached) does not grow with the number of forums.
     */
    public function testQueryCountIsFixed(): void
    {
        $category = $this->forums->create(ForumType::Category, 'C', 'c')->id;

        foreach (range(1, 3) as $i) {
            $this->thread($this->forums->create(ForumType::Forum, 'F' . $i, 'f-' . $i, $category)->id, 'T' . $i);
        }

        $small = [$this->queries(null), $this->queries($this->alice)];
        $this->board(10, 10);
        $large = [$this->queries(null), $this->queries($this->alice)];

        fwrite(STDERR, sprintf("forum_index_queries guest=%d member=%d\n", $large[0], $large[1]));
        self::assertSame($small, $large);
        self::assertSame([5, 9], $large, 'recorded: guest 5, member 9 (groups and rules of the member)');
    }

    /**
     * Benchmark case `forum_index_medium`: 10 categories with 10 forums each, every forum with a last post; the
     * whole handler (subject, scopes from cache, index query, Twig render) for a member.
     */
    public function testMediumBoardLatencyAndMemory(): void
    {
        $this->board(10, 10);
        // Translator and Twig (with its compiled-template cache) are built once, as they are per process in
        // production; every call builds a fresh handler, so it resolves the subject, reads the scopes from the
        // cache and runs the index query like a request does.
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $views = TwigFactory::create($app, $this->storage->path, $translator);
        $html = (string) $this->render($this->alice)->getContent();
        $result = BenchmarkHarness::measure(
            fn(): Response => $this->handler($this->alice, $translator, $views)->handle(Request::create('/forums'), []),
            50,
            5,
        );

        fwrite(STDERR, sprintf(
            "forum_index_medium p50 %.2f ms  p95 %.2f ms  added %.1f MiB  process peak %d MiB  (110 entries)\n",
            $result['p50_ms'],
            $result['p95_ms'],
            $result['max_added_bytes'] / 1048576,
            $result['max_peak_bytes'] / 1048576,
        ));
        self::assertSame(110, substr_count($html, '<li class="forum-depth-'));
        self::assertLessThan(100.0, $result['p95_ms']);
        // The 64 MiB budget is per request: what one index render allocates, not the PHPUnit process size.
        self::assertLessThan(64 * 1048576, $result['max_added_bytes']);
    }

    private function queries(?int $userId): int
    {
        $this->render($userId);
        $this->counter->count = 0;
        $this->render($userId);

        return $this->counter->count;
    }

    /**
     * @param int $categories Each gets $forums forums with one thread each.
     */
    private function board(int $categories, int $forums): void
    {
        foreach (range(1, $categories) as $c) {
            $category = $this->forums->create(ForumType::Category, 'Category ' . $c, 'category-' . $c)->id;

            foreach (range(1, $forums) as $f) {
                $name = $c . '.' . $f;
                $forum = $this->forums->create(ForumType::Forum, 'Forum ' . $name, 'forum-' . $c . '-' . $f, $category);
                $this->thread($forum->id, 'Topic ' . $name);
            }
        }
    }

    private function thread(int $forumId, string $title): void
    {
        $posts = new DbalPostRepository($this->connection);
        $at = (new DateTimeImmutable('2026-11-01 08:00:00', new DateTimeZone('UTC')))
            ->modify('+' . (++$this->minute) . ' minutes');
        $admin = $this->resolver()->user($this->alice, [BuiltInGroup::Registered->value]);

        (new CreateThread(
            $this->forums,
            $this->visibility(),
            $posts,
            new DbalThreadWriter(
                $this->connection,
                new DbalThreadRepository($this->connection),
                new Outbox($this->connection),
                new PostingLock($this->connection),
            ),
            clock: static fn(): DateTimeImmutable => $at,
        ))->create($admin, $forumId, $title, 'Body');
    }

    private function render(?int $userId): Response
    {
        return $this->handler($userId)->handle(Request::create('/forums'), []);
    }

    private function handler(
        ?int $userId,
        ?MessageTranslator $translator = null,
        ?TemplateRenderer $views = null,
        ?SiteSettings $settings = null,
    ): ForumIndexHandler {
        $app = dirname(__DIR__, 2);
        $translator ??= TranslatorFactory::create($app . '/translations', 'en');
        $views ??= TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-no-storage', $translator);
        $router = new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
        $session = new ArraySession();

        if ($userId !== null) {
            $session->set('_user_id', $userId);
        }

        $csrf = new CsrfTokens($session);

        return new ForumIndexHandler(
            $translator,
            $views,
            new RequestSubject(
                new AuthSession($session, $csrf, new MemoryUsers(), new ResponseCookies()),
                new DbalUsergroupRepository($this->connection),
                $this->resolver(),
            ),
            $this->visibility(),
            new DbalForumIndex($this->connection),
            $router,
            $settings,
            $settings === null ? null : new DiscussionFeed(
                $translator,
                $views,
                new DbalThreadRepository($this->connection),
                $router,
                new ErrorResponder($translator),
                new DbalReadTracker($this->connection),
                static fn(): DateTimeImmutable => new DateTimeImmutable('2026-11-02 08:00:00', new DateTimeZone('UTC')),
            ),
        );
    }

    /**
     * @return list<string> Category and forum headings in page order, as escaped HTML.
     */
    private static function titles(string $html): array
    {
        preg_match_all('~<h[23]>(.*?)</h[23]>~s', $html, $matches);

        return array_map(static fn(string $heading): string => trim(strip_tags($heading)), $matches[1]);
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
}
