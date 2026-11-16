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
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
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
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\EditPost;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Http\EditPostHandler;
use Nolviko\Modules\Threads\Http\PostHistoryHandler;
use Nolviko\Modules\Threads\Infrastructure\DbalPostEditor;
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
 * M03.06 on MySQL 8.4: authors edit their own posts within the window, moderators any post; every edit stores a
 * revision with the previous content and reason, the new rendering and an outbox event; another user's post ID
 * in the URL is refused with 403 (IDOR), an unreadable post is a 404.
 */
final class EditPostTest extends TestCase
{
    private Connection $connection;

    private QueryCounter $counter;

    private DbalPostRepository $posts;

    private DbalPermissionRuleStore $rules;

    private TemporaryDirectory $storage;

    private int $alice;

    private int $bob;

    private int $forum;

    private int $thread;

    private int $alicePost;

    private int $bobPost;

    private DateTimeImmutable $now;

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
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $this->storage = new TemporaryDirectory();
        $users = new DbalUserRepository($this->connection);
        $this->alice = $users->create('alice', 'alice@example.com', 'h', UserStatus::Active)->id;
        $this->bob = $users->create('bob', 'bob@example.com', 'h', UserStatus::Active)->id;
        $this->now = new DateTimeImmutable('2026-11-01 12:00:00', new DateTimeZone('UTC'));
        $this->forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'General', 'general')->id;
        [$this->thread, $this->alicePost] = $this->thread($this->forum, $this->alice);
        $this->bobPost = $this->posts->add($this->thread, $this->bob, 'Bob says', 'bbcode', $this->now);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testEachEditStoresARevisionTheRenderingAndAnEvent(): void
    {
        $service = $this->service($this->now->modify('+5 minutes'));

        self::assertTrue($service->edit($this->member($this->alice), $this->alicePost, '[b]v2[/b]', '  typo  '));
        self::assertTrue($service->edit($this->member($this->alice), $this->alicePost, '[i]v3[/i]'));

        $post = $this->posts->find($this->alicePost);
        $revisions = $this->posts->revisions($this->alicePost);
        $events = $this->connection->fetchFirstColumn(
            "SELECT dedupe_key FROM outbox_events WHERE event_type = 'post.edited' ORDER BY id",
        );

        self::assertNotNull($post);
        self::assertSame('[i]v3[/i]', $post->content);
        self::assertSame((new BbcodeRenderer())->render('[i]v3[/i]'), $post->renderedHtml);
        self::assertSame(BbcodeRenderer::VERSION, $post->rendererVersion);
        self::assertSame($this->alice, $post->editedBy);
        self::assertSame(['First post', '[b]v2[/b]'], array_column($revisions, 'previous_content'));
        self::assertSame(['typo', null], array_column($revisions, 'change_reason'));
        self::assertCount(2, $events, 'one event per edit, not one per post');
    }

    public function testHistoryShowsEarlierVersionsToTheAuthorOnly(): void
    {
        (new DbalUsergroupRepository($this->connection))->addMember($this->alice, BuiltInGroup::Registered->value);
        (new DbalUsergroupRepository($this->connection))->addMember($this->bob, BuiltInGroup::Registered->value);
        $service = $this->service($this->now->modify('+5 minutes'));
        $service->edit($this->member($this->alice), $this->alicePost, '[b]v2[/b] <script>', 'typo');
        $service->edit($this->member($this->alice), $this->alicePost, 'v3');
        $history = function (?int $userId): Response {
            $session = new ArraySession();

            if ($userId !== null) {
                $session->set('_user_id', $userId);
            }

            $app = dirname(__DIR__, 2);
            $translator = TranslatorFactory::create($app . '/translations', 'en');

            return (new PostHistoryHandler(
                $translator,
                TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-no-storage', $translator),
                new RequestSubject(
                    new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
                    new DbalUsergroupRepository($this->connection),
                    $this->resolver(),
                ),
                $this->posts,
                new DbalThreadRepository($this->connection),
                new DbalUserRepository($this->connection),
                $this->visibility(),
                new Router(CompiledRoutes::compile(CoreRoutes::definitions())),
                new ErrorResponder($translator),
            ))->handle(Request::create('/posts/' . $this->alicePost . '/history'), [
                'post' => (string) $this->alicePost,
            ]);
        };

        $own = $history($this->alice);
        $html = (string) $own->getContent();
        self::assertSame(200, $own->getStatusCode());
        self::assertSame('no-store, private', $own->headers->get('Cache-Control'));
        self::assertStringContainsString('>v3</pre>', $html);
        self::assertStringContainsString('[b]v2[/b] &lt;script&gt;', $html, 'escaped source, never rendered');
        self::assertStringContainsString('Reason: typo', $html);
        self::assertLessThan(strpos($html, 'First post'), strpos($html, '[b]v2[/b]'), 'newest first');
        self::assertSame(404, $history($this->bob)->getStatusCode(), 'another member');
        self::assertSame(404, $history(null)->getStatusCode(), 'a guest');
    }

    public function testUnchangedContentStoresNothing(): void
    {
        self::assertFalse($this->service()->edit($this->member($this->alice), $this->alicePost, "First post\r\n"));
        self::assertSame([], $this->posts->revisions($this->alicePost));
    }

    public function testAnotherUsersPostIsForbiddenButModeratorsMayEditIt(): void
    {
        self::assertSame('post_edit_denied', $this->violation($this->member($this->alice), $this->bobPost));
        self::assertSame('post_edit_denied', $this->violation($this->resolver()->guest(), $this->bobPost));
        self::assertTrue($this->service()->edit($this->moderator($this->alice), $this->bobPost, 'Cleaned', 'spam'));
        self::assertSame($this->alice, $this->posts->revisions($this->bobPost)[0]['editor_id']);
    }

    public function testTheWindowAndTheThreadLockBindAuthorsOnly(): void
    {
        // No limit by default since 2026-11-09 (0050); an administrator can set one.
        self::assertTrue($this->service($this->now->modify('+30 days'))->edit(
            $this->member($this->alice),
            $this->alicePost,
            'Much later',
        ));
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, EditPost::WINDOW, 60);
        $late = $this->now->modify('+60 minutes');

        try {
            $this->service($late)->edit($this->member($this->alice), $this->alicePost, 'Too late');
            self::fail('edited after the window');
        } catch (ThreadRuleViolation $violation) {
            self::assertSame(['post_edit_window_expired', ['minutes' => 60]], [
                $violation->diagnostic,
                $violation->parameters,
            ]);
        }

        self::assertTrue($this->service($late)->edit($this->moderator($this->alice), $this->alicePost, 'Mod late'));

        $this->rules->setGroupRule(BuiltInGroup::Registered->value, EditPost::WINDOW, 0);
        self::assertTrue(
            $this->service($late)->edit($this->member($this->bob), $this->bobPost, 'No limit'),
            '0: no limit',
        );

        $this->rules->setGroupRule(BuiltInGroup::Registered->value, EditPost::WINDOW, 60);
        $this->connection->update('threads', ['is_locked' => 1], ['id' => $this->thread]);
        self::assertSame('thread_locked', $this->violation($this->member($this->bob), $this->bobPost));
        self::assertTrue($this->service()->edit($this->moderator($this->alice), $this->bobPost, 'Mod in lock'));

        $this->connection->update('threads', ['is_locked' => 0], ['id' => $this->thread]);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, EditPost::EDIT_OWN, false);
        self::assertSame('post_edit_denied', $this->violation($this->member($this->bob), $this->bobPost));
    }

    public function testUnreadablePostsAreNotFound(): void
    {
        $staff = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'Staff', 'staff')->id;
        [, $staffPost] = $this->thread($staff, $this->alice);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.view', false, $staff);
        $pending = $this->posts->add($this->thread, $this->bob, 'Waiting', 'bbcode', $this->now, PostStatus::Pending);

        self::assertSame('post_not_found', $this->violation($this->member($this->alice), $staffPost));
        self::assertSame('post_not_found', $this->violation($this->member($this->alice), $pending));
        self::assertSame('post_not_found', $this->violation($this->member($this->alice), 999999));
        self::assertTrue($this->service()->edit($this->member($this->bob), $pending, 'Waiting, edited'));
    }

    public function testInvalidInputIsRejected(): void
    {
        $member = $this->member($this->alice);

        self::assertSame('post_content_invalid', $this->violation($member, $this->alicePost, " \n "));
        $long = str_repeat('r', EditPost::MAX_REASON_LENGTH + 1);

        self::assertSame('post_reason_invalid', $this->violation($member, $this->alicePost, 'New', $long));
        self::assertSame('post_reason_invalid', $this->violation($member, $this->alicePost, 'New', "a\u{0007}"));
        self::assertSame([], $this->posts->revisions($this->alicePost));
    }

    public function testHandlerRefusesAnotherUsersPostIdWith403(): void
    {
        (new DbalUsergroupRepository($this->connection))->addMember($this->alice, BuiltInGroup::Registered->value);
        $session = new ArraySession();
        $session->set('_user_id', $this->alice);
        $form = $this->handler($session)->handle($this->request('GET', $this->alicePost), [
            'post' => (string) $this->alicePost,
        ]);
        $idor = $this->handler($session)->handle(
            $this->request('POST', $this->bobPost, ['content' => 'Hijacked']),
            ['post' => (string) $this->bobPost],
        );
        $saved = $this->handler($session)->handle(
            $this->request('POST', $this->alicePost, ['content' => '<b>new</b>', 'reason' => 'fix']),
            ['post' => (string) $this->alicePost],
        );
        $guest = $this->handler(new ArraySession())->handle($this->request('GET', $this->alicePost), [
            'post' => (string) $this->alicePost,
        ]);

        self::assertSame(Response::HTTP_OK, $form->getStatusCode());
        self::assertStringContainsString('>First post</textarea>', (string) $form->getContent());
        self::assertSame(Response::HTTP_FORBIDDEN, $idor->getStatusCode());
        self::assertStringNotContainsString('<textarea', (string) $idor->getContent());
        self::assertSame('Bob says', $this->posts->find($this->bobPost)?->content);
        self::assertSame(Response::HTTP_SEE_OTHER, $saved->getStatusCode());
        self::assertSame('<b>new</b>', $this->posts->find($this->alicePost)?->content);
        self::assertSame('/login', $guest->headers->get('Location'));
    }

    /**
     * Benchmark case `post_edit`: the whole service call (permission scope from cache, render, transaction with
     * row lock, revision, rendering and outbox event).
     */
    public function testQueryCountAndLatency(): void
    {
        $moderator = $this->moderator($this->alice);
        $service = $this->service();
        $tick = 0;
        $queries = [];
        $result = BenchmarkHarness::measure(function () use ($moderator, $service, &$tick, &$queries): void {
            $this->counter->count = 0;
            $service->edit($moderator, $this->bobPost, str_repeat('[b]Edited[/b] text ', 50) . ++$tick, 'bench');
            $queries[] = $this->counter->count;
        }, 50, 5);

        fwrite(STDERR, sprintf(
            "post_edit p50 %.4f ms  p95 %.4f ms  peak %d MiB  queries %d\n",
            $result['p50_ms'],
            $result['p95_ms'],
            $result['max_peak_bytes'] / 1048576,
            max($queries),
        ));
        self::assertLessThanOrEqual(16, max($queries));
        self::assertLessThan(250.0, $result['p95_ms']);
    }

    private function violation(
        SubjectPermissions $subject,
        int $postId,
        string $content = 'Changed',
        string $reason = '',
    ): string {
        try {
            $this->service()->edit($subject, $postId, $content, $reason);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('no violation');
    }

    /**
     * @return array{int, int} Thread ID and first post ID.
     */
    private function thread(int $forumId, int $userId): array
    {
        $thread = (new DbalThreadRepository($this->connection))
            ->create($forumId, $userId, 'Topic', 'topic', $this->now);

        return [$thread, $this->posts->add($thread, $userId, 'First post', 'bbcode', $this->now)];
    }

    private function member(int $userId): SubjectPermissions
    {
        return $this->resolver()->user($userId, [BuiltInGroup::Registered->value]);
    }

    private function moderator(int $userId): SubjectPermissions
    {
        return $this->resolver()->user($userId, [BuiltInGroup::Registered->value, BuiltInGroup::Moderators->value]);
    }

    private function service(?DateTimeImmutable $now = null): EditPost
    {
        $at = $now ?? $this->now->modify('+1 minute');

        return new EditPost(
            $this->posts,
            new DbalThreadRepository($this->connection),
            $this->visibility(),
            new DbalPostEditor($this->connection, $this->posts, new Outbox($this->connection)),
            clock: static fn(): DateTimeImmutable => $at,
        );
    }

    private function handler(ArraySession $session): EditPostHandler
    {
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $csrf = new CsrfTokens($session);

        return new EditPostHandler(
            $translator,
            TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-no-storage', $translator),
            new RequestSubject(
                new AuthSession($session, $csrf, new MemoryUsers(), new ResponseCookies()),
                new DbalUsergroupRepository($this->connection),
                $this->resolver(),
            ),
            $this->service(),
            $csrf,
            new Router(CompiledRoutes::compile(CoreRoutes::definitions())),
            new ErrorResponder($translator),
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private function request(string $method, int $postId, array $fields = []): Request
    {
        return Request::create('/posts/' . $postId . '/edit', $method, $fields);
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
