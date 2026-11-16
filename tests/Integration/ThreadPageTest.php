<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use DOMDocument;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Http\SeoMetadata;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Domain\PagedPost;
use Nolviko\Modules\Posts\Domain\PostRenderer;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Threads\Http\ThreadPageHandler;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
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
 * M03.10 on MySQL 8.4 (ADR-014): numbered thread pages with canonical URLs, read forwards in the first half and
 * from the end in the second; members never see pending or hidden posts, moderators see them marked on the page
 * where they fall; bodies come from the render cache and hostile content stays inert; first, middle and last
 * page of a 100,000-post thread are benchmarked.
 */
final class ThreadPageTest extends TestCase
{
    private Connection $connection;

    private QueryCounter $counter;

    private DbalPostRepository $posts;

    private DbalForumRepository $forums;

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
        $this->posts = new DbalPostRepository($this->connection);
        $this->forums = new DbalForumRepository($this->connection);
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
     * 45 visible posts (every 7th post hidden, every 11th pending) on pages of 10: every page, read forwards or
     * from the end, holds exactly the visible posts at its positions.
     */
    public function testEditLinkForTheAuthorAndModeratorsOnly(): void
    {
        $thread = $this->thread(2, 0, 0);
        $first = (int) $this->connection->fetchOne('SELECT first_post_id FROM threads WHERE id = ?', [$thread]);
        $link = '/posts/' . $first . '/edit';

        self::assertStringContainsString($link, (string) $this->get($thread, null, $this->alice)->getContent());
        self::assertStringContainsString($link, (string) $this->get($thread, null, $this->mod)->getContent());
        self::assertStringNotContainsString($link, (string) $this->get($thread, null, null)->getContent());
    }

    public function testSearchEnginesGetForumStructuredDataAndMetaTags(): void
    {
        $thread = $this->thread(3, 0, 0);
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $router = new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
        $seo = new SeoMetadata(new SiteUrl('https://forum.example'));
        $seo->bind(
            ['_route' => CoreRoutes::THREAD_SHOW, 'slug' => 'topic', 'thread' => (string) $thread],
            $router,
            false,
        );
        $views = TwigFactory::create(
            $app,
            sys_get_temp_dir() . '/nolviko-no-storage',
            $translator,
            urls: $router,
            seo: $seo,
        );
        $html = (string) $this->get($thread, null, null, $translator, $views)->getContent();

        preg_match_all('~<script type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $blocks);
        $data = array_map(
            static fn(string $json): array => json_decode($json, true, 32, JSON_THROW_ON_ERROR),
            $blocks[1],
        );
        $types = array_column($data, '@type');
        self::assertSame(['BreadcrumbList', 'DiscussionForumPosting'], $types);
        $posting = $data[1];
        self::assertSame('Topic', $posting['headline']);
        self::assertSame('https://forum.example/threads/topic-' . $thread, $posting['url']);
        self::assertSame('alice', $posting['author']['name']);
        self::assertCount(2, $posting['comment'], 'replies on the page');
        self::assertSame(2, $posting['interactionStatistic']['userInteractionCount']);
        self::assertNotSame('', $posting['text']);
        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('max-image-preview:large', $html);
        self::assertSame(1, substr_count($html, 'rel="canonical"'), 'one canonical link');
        self::assertMatchesRegularExpression('~<meta name="description" content="[^"]+">~', $html);
    }

    public function testEveryPageHoldsItsPositionsInBothDirections(): void
    {
        $thread = $this->thread(45, 7, 11);
        $visible = $this->visibleIds($thread);

        foreach (range(1, 5) as $page) {
            $ids = array_map(
                static fn(PagedPost $paged): int => $paged->post->id,
                $this->posts->threadPage($thread, $page, 10, 45),
            );

            self::assertSame(array_slice($visible, ($page - 1) * 10, 10), $ids, 'page ' . $page);
        }

        self::assertSame([], $this->posts->threadPage($thread, 6, 10, 45));
    }

    public function testBreadcrumbNamesTheForumWithALinkAndNothingWithoutTheRepository(): void
    {
        $thread = $this->thread(1, 0, 0);
        $html = (string) $this->get($thread, null, $this->alice, trail: true)->getContent();
        $crumb = substr($html, (int) strpos($html, '<nav class="crumb"'), 600);

        self::assertStringContainsString('<a href="/forums/' . $this->forum . '"><bdi>General</bdi></a>', $crumb);
        self::assertStringNotContainsString('General', (string) strstr(
            (string) $this->get($thread, null, $this->alice)->getContent(),
            '<h1',
            true,
        ));
    }

    public function testModeratorsSeeHiddenPostsWhereTheyFallWithTheSameNumbering(): void
    {
        $thread = $this->thread(45, 7, 11);
        $all = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM posts WHERE thread_id = ? ORDER BY id',
            [$thread],
        ));
        $visible = $this->visibleIds($thread);
        $seen = [];

        foreach (range(1, 5) as $page) {
            $paged = $this->posts->threadPage($thread, $page, 10, 45, true);
            $ids = array_map(static fn(PagedPost $item): int => $item->post->id, $paged);
            $visibleOnPage = array_values(array_intersect($ids, $visible));

            self::assertSame(array_slice($visible, ($page - 1) * 10, 10), $visibleOnPage, 'same numbering');
            $seen = [...$seen, ...$ids];
        }

        self::assertSame($all, $seen, 'every post exactly once, in order');
    }

    public function testPagesCanonicalUrlsRedirectsAndNotFound(): void
    {
        $thread = $this->thread(45, 0, 0);
        $first = $this->get($thread, null, null);
        $third = $this->get($thread, 3, null);
        $thirdHtml = (string) $third->getContent();
        $base = '/threads/topic-' . $thread;

        self::assertSame(Response::HTTP_OK, $first->getStatusCode());
        self::assertStringContainsString('<link rel="canonical" href="' . $base . '">', (string) $first->getContent());
        self::assertStringContainsString('<link rel="canonical" href="' . $base . '/page-3">', $thirdHtml);
        self::assertStringContainsString('<title>Topic – page 3</title>', (string) $third->getContent());
        self::assertStringContainsString('rel="prev" href="' . $base . '/page-2"', (string) $third->getContent());
        self::assertStringContainsString('aria-current="page">3</li>', (string) $third->getContent());
        self::assertSame(10, substr_count((string) $third->getContent(), '<article id="post-'));
        // Post numbers are places in the thread (2026-11-14): page 3 of ten per page starts at #21.
        self::assertStringContainsString('>#21</a>', $thirdHtml);
        self::assertStringContainsString('>#1</a>', (string) $first->getContent());
        self::assertSame([Response::HTTP_MOVED_PERMANENTLY, $base], [
            $this->get($thread, 1, null)->getStatusCode(),
            $this->get($thread, 1, null)->headers->get('Location'),
        ]);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->get($thread, 6, null)->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $this->get(999999, null, null)->getStatusCode());

        // Owner decision 2026-11-14: one address per page. The id form and an outdated slug answer 301 with the
        // canonical address, the query string kept; a thread the reader may not see stays a 404 (no title leak).
        $idOnly = $this->get($thread, null, null, extra: ['slug' => null]);
        self::assertSame([Response::HTTP_MOVED_PERMANENTLY, $base], [$idOnly->getStatusCode(),
            $idOnly->headers->get('Location')]);
        $renamed = $this->get($thread, 3, null, extra: ['slug' => 'old-title'], query: 'utm=x');
        self::assertSame([Response::HTTP_MOVED_PERMANENTLY, $base . '/page-3?utm=x'], [$renamed->getStatusCode(),
            $renamed->headers->get('Location')]);
        $this->connection->update('threads', ['status' => 'hidden'], ['id' => $thread]);
        $hidden = $this->get($thread, null, $this->alice, extra: ['slug' => null]);
        self::assertSame(Response::HTTP_NOT_FOUND, $hidden->getStatusCode());
        self::assertNull($hidden->headers->get('Location'));
    }

    public function testVisibilityOfThreadsAndPosts(): void
    {
        $thread = $this->thread(3, 0, 0);
        $hidden = $this->posts->add($thread, $this->alice, 'Deleted text', 'bbcode', self::at(10), PostStatus::Hidden);
        $member = (string) $this->get($thread, null, $this->alice)->getContent();
        $moderator = (string) $this->get($thread, null, $this->mod)->getContent();

        self::assertStringNotContainsString('Deleted text', $member);
        self::assertStringContainsString('Deleted text', $moderator);
        self::assertStringContainsString('<article id="post-' . $hidden . '" class="post post-hidden">', $moderator);
        self::assertStringContainsString('Deleted: only moderators see this.', $moderator);
        self::assertStringContainsString('>Reply</a>', $member);
        self::assertStringNotContainsString('>Reply</a>', (string) $this->get($thread, null, null)->getContent());

        $this->connection->update('threads', ['status' => 'hidden'], ['id' => $thread]);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->get($thread, null, $this->alice)->getStatusCode());
        self::assertSame(Response::HTTP_OK, $this->get($thread, null, $this->mod)->getStatusCode());

        $this->connection->update('threads', ['status' => 'visible'], ['id' => $thread]);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.thread.view', false, $this->forum);
        self::assertSame(Response::HTTP_NOT_FOUND, $this->get($thread, null, $this->alice)->getStatusCode());

        $stub = (new DbalThreadRepository($this->connection))
            ->create($this->forum, null, 'Moved', 'moved', self::at(1));
        $this->connection->update('threads', ['moved_to_thread_id' => $thread], ['id' => $stub]);
        $redirect = $this->get($stub, null, null);
        self::assertSame([Response::HTTP_MOVED_PERMANENTLY, '/threads/topic-' . $thread], [
            $redirect->getStatusCode(),
            $redirect->headers->get('Location'),
        ]);
    }

    public function testBodiesComeFromTheRenderCacheAndHostileContentIsInert(): void
    {
        $thread = $this->thread(1, 0, 0);
        $hostile = '[url=javascript:alert(1)]x[/url] <script>alert(1)</script> [img]javascript:alert(2)[/img] '
            . '[url=https://example.com" onmouseover="alert(3)]y[/url] [color=red;background:url(x)]z[/color]';
        $post = $this->posts->add($thread, $this->alice, $hostile, 'bbcode', self::at(5));
        $this->connection->update('threads', ['reply_count' => 1], ['id' => $thread]);

        $html = (string) $this->get($thread, null, null)->getContent();
        $body = substr($html, (int) strpos($html, '<article id="post-' . $post . '"'));
        // The post only: the page's own deferred scripts (quote.js, embed.js) follow the articles.
        $body = substr($body, 0, (int) strpos($body, '</article>') + strlen('</article>'));
        $stored = $this->connection->fetchAssociative(
            'SELECT rendered_html, renderer_version FROM posts WHERE id = ?',
            [$post],
        );

        self::assertIsArray($stored);
        self::assertSame(BbcodeRenderer::VERSION, (int) $stored['renderer_version'], 'rendered once, cached');
        // Rejected markup may stay as escaped text; what must never appear is live markup.
        self::assertSame([], self::unsafeMarkup($body));
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    /**
     * Script elements, event-handler attributes and script URLs in the parsed HTML.
     *
     * @return list<string>
     */
    private static function unsafeMarkup(string $html): array
    {
        $document = new DOMDocument();
        $document->loadHTML('<!doctype html><meta charset="utf-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $found = [];

        foreach ($document->getElementsByTagName('*') as $element) {
            if (in_array($element->tagName, ['script', 'iframe', 'object', 'embed'], true)) {
                $found[] = $element->tagName;
            }

            foreach ($element->attributes ?? [] as $attribute) {
                $value = strtolower(trim($attribute->value));

                if (str_starts_with($attribute->name, 'on') || str_starts_with($value, 'javascript:')) {
                    $found[] = $element->tagName . '@' . $attribute->name;
                }
            }
        }

        return $found;
    }

    /**
     * Benchmark case `thread_page_large`: a 100,000-post thread (5 % hidden; 95,000 visible, 4,750 pages of 20)
     * through the repository (first, middle and last page) and the whole handler (page 1 and last page).
     */
    public function testLargeThreadFirstMiddleAndLastPage(): void
    {
        $thread = $this->bulkThread(100000);
        $visible = 95000;
        $pages = 4750;
        $out = '';

        foreach (['first' => 1, 'middle' => intdiv($pages, 2), 'last' => $pages] as $name => $page) {
            $queries = 0;
            $result = BenchmarkHarness::measure(function () use ($thread, $page, $visible, &$queries): void {
                $this->counter->count = 0;
                $this->posts->threadPage($thread, $page, 20, $visible);
                $queries = $this->counter->count;
            }, 30, 3);
            $out .= sprintf(
                "thread_page_large %s p50 %.2f ms  p95 %.2f ms  queries %d\n",
                $name,
                $result['p50_ms'],
                $result['p95_ms'],
                $queries,
            );
            self::assertLessThan(60.0, $result['p95_ms'], $name);
        }

        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $views = TwigFactory::create($app, $this->storage->path, $translator);

        foreach (['handler first' => null, 'handler last' => $pages] as $name => $page) {
            $this->get($thread, $page, $this->alice, $translator, $views, 20);
            $result = BenchmarkHarness::measure(
                fn(): Response => $this->get($thread, $page, $this->alice, $translator, $views, 20),
                30,
                3,
            );
            $out .= sprintf(
                "thread_page_large %s p50 %.2f ms  p95 %.2f ms  peak %d MiB\n",
                $name,
                $result['p50_ms'],
                $result['p95_ms'],
                $result['max_peak_bytes'] / 1048576,
            );
        }

        fwrite(STDERR, $out);
    }

    /**
     * A thread whose posts are created in order; every $hiddenEvery-th post is hidden and every
     * $pendingEvery-th pending (0 = none), until $visible posts are visible.
     */
    private function thread(int $visible, int $hiddenEvery, int $pendingEvery): int
    {
        $thread = (new DbalThreadRepository($this->connection))
            ->create($this->forum, $this->alice, 'Topic', 'topic', self::at(0));
        $count = 0;

        for ($i = 1; $count < $visible; ++$i) {
            $status = PostStatus::Visible;

            if ($i > 1 && $hiddenEvery > 0 && $i % $hiddenEvery === 0) {
                $status = PostStatus::Hidden;
            } elseif ($i > 1 && $pendingEvery > 0 && $i % $pendingEvery === 0) {
                $status = PostStatus::Pending;
            }

            $id = $this->posts->add($thread, $this->alice, 'Post ' . $i, 'bbcode', self::at($i), $status);

            if ($i === 1) {
                $this->connection->update('threads', ['first_post_id' => $id], ['id' => $thread]);
            }

            $count += $status === PostStatus::Visible ? 1 : 0;
        }

        $this->connection->update('threads', ['reply_count' => $visible - 1], ['id' => $thread]);

        return $thread;
    }

    private function bulkThread(int $posts): int
    {
        $thread = (new DbalThreadRepository($this->connection))
            ->create($this->forum, $this->alice, 'Hot', 'hot', self::at(0));
        $values = [];

        for ($i = 1; $i <= $posts; ++$i) {
            $values[] = sprintf(
                "(%d, %d, 'Post %d [b]bold[/b] %s', 'bbcode', '%s', '2026-11-01 00:00:00', '2026-11-01 00:00:00')",
                $thread,
                $this->alice,
                $i,
                str_repeat('text ', 40),
                $i > 1 && $i % 20 === 0 ? 'hidden' : 'visible',
            );

            if (count($values) === 2000) {
                $this->connection->executeStatement(
                    'INSERT INTO posts (thread_id, user_id, content, content_format, status, created_at, updated_at) '
                        . 'VALUES ' . implode(', ', $values),
                );
                $values = [];
            }
        }

        $this->connection->executeStatement(
            'UPDATE threads SET reply_count = (SELECT COUNT(*) - 1 FROM posts '
                . "WHERE thread_id = ? AND status = 'visible') WHERE id = ?",
            [$thread, $thread],
        );
        TestDatabase::analyze($this->connection, 'posts');

        return $thread;
    }

    /**
     * @return list<int>
     */
    private function visibleIds(int $thread): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM posts WHERE thread_id = ? AND status = 'visible' ORDER BY id",
            [$thread],
        ));
    }

    private function get(
        int $thread,
        ?int $page,
        ?int $userId,
        ?MessageTranslator $translator = null,
        ?TemplateRenderer $views = null,
        int $perPage = 10,
        bool $trail = false,
        array $extra = [],
        string $query = '',
    ): Response {
        $app = dirname(__DIR__, 2);
        $translator ??= TranslatorFactory::create($app . '/translations', 'en');
        $session = new ArraySession();

        if ($userId !== null) {
            $session->set('_user_id', $userId);
        }

        $csrf = new CsrfTokens($session);
        $handler = new ThreadPageHandler(
            $translator,
            $views ?? TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-no-storage', $translator),
            new RequestSubject(
                new AuthSession($session, $csrf, new MemoryUsers(), new ResponseCookies()),
                new DbalUsergroupRepository($this->connection),
                $this->resolver(),
            ),
            new DbalThreadRepository($this->connection),
            $this->posts,
            new PostRenderer($this->posts),
            $this->visibility(),
            new Router(CompiledRoutes::compile(CoreRoutes::definitions())),
            new ErrorResponder($translator),
            $perPage,
            forums: $trail ? $this->forums : null,
        );
        // The canonical route's parameters: the stored slug (the router would pass it from the address).
        $slug = $this->connection->fetchOne('SELECT slug FROM threads WHERE id = ?', [$thread]);
        $parameters = ['thread' => (string) $thread] + ($slug === false ? [] : ['slug' => (string) $slug])
            + ($page === null ? [] : ['page' => (string) $page]);

        // `$extra` overrides route parameters; null removes one (the id-only route has no slug).
        $parameters = array_filter(
            array_merge($parameters, $extra),
            static fn(?string $value): bool => $value !== null,
        );

        return $handler->handle(
            Request::create('/threads/' . $thread . ($query === '' ? '' : '?' . $query)),
            $parameters,
        );
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
