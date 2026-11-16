<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Core\Events\Outbox;
use Nolviko\Core\Extensions\BeforeHooks;
use Nolviko\Core\Extensions\HookDecision;
use Nolviko\Core\Extensions\PostingInput;
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
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\DraftContext;
use Nolviko\Modules\Threads\Domain\Drafts;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Threads\Http\CreateThreadHandler;
use Nolviko\Modules\Threads\Infrastructure\DbalDrafts;
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
 * M03.04 on MySQL 8.4: one transaction writes thread, first post with its rendering, first/last post IDs,
 * forum statistics and the outbox event, and a failure at the last step leaves nothing behind. Hidden, locked
 * and forbidden forums, flood and approval rules are enforced, and the HTTP handler maps them to responses.
 */
final class CreateThreadTest extends TestCase
{
    private Connection $connection;

    private QueryCounter $counter;

    private DbalForumRepository $forums;

    private DbalPermissionRuleStore $rules;

    private TemporaryDirectory $storage;

    private int $alice;

    private int $forum;

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
        $this->forums = new DbalForumRepository($this->connection);
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $this->storage = new TemporaryDirectory();
        $this->alice = (new DbalUserRepository($this->connection))
            ->create('alice', 'alice@example.com', 'h', UserStatus::Active)->id;
        $this->forum = $this->forums->create(ForumType::Forum, 'General', 'general')->id;
        $this->now = new DateTimeImmutable('2026-11-01 12:00:00', new DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testOneTransactionWritesEverything(): void
    {
        $created = $this->service()->create($this->member(), $this->forum, '  Hello   wörld  ', "[b]Hi[/b]\r\nthere");

        $thread = $this->connection->fetchAssociative('SELECT * FROM threads WHERE id = ?', [$created->threadId]);
        $post = $this->connection->fetchAssociative('SELECT * FROM posts WHERE id = ?', [$created->postId]);
        $stats = $this->stats();
        $event = $this->connection->fetchAssociative('SELECT * FROM outbox_events');

        self::assertIsArray($thread);
        self::assertIsArray($post);
        self::assertIsArray($event);
        self::assertSame(ThreadStatus::Visible, $created->status);
        self::assertSame(
            ['Hello wörld', 'hello-world', 'visible'],
            [$thread['title'], $thread['slug'], $thread['status']],
        );
        self::assertSame(
            [$created->postId, $created->postId],
            [(int) $thread['first_post_id'], (int) $thread['last_post_id']],
        );
        self::assertSame("[b]Hi[/b]\nthere", $post['content']);
        self::assertSame((new BbcodeRenderer())->render("[b]Hi[/b]\nthere"), $post['rendered_html']);
        self::assertStringStartsWith('<strong>Hi</strong>', (string) $post['rendered_html']);
        self::assertSame(BbcodeRenderer::VERSION, (int) $post['renderer_version']);
        self::assertSame([1, 1, $created->postId], $stats);
        self::assertSame('thread.created', $event['event_type']);
        self::assertSame($created->threadId, (int) $event['aggregate_id']);
    }

    public function testAFailureAtTheLastStepLeavesNothingBehind(): void
    {
        $this->connection->executeStatement('ALTER TABLE outbox_events RENAME TO outbox_events_moved');

        try {
            $this->service()->create($this->member(), $this->forum, 'Lost', 'Body');
            self::fail('the outbox insert should have failed');
        } catch (TableNotFoundException) {
        } finally {
            $this->connection->executeStatement('ALTER TABLE outbox_events_moved RENAME TO outbox_events');
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM threads'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM posts'));
        self::assertSame([0, 0, null], $this->stats());
        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testHiddenLockedAndForbiddenForumsAreDenied(): void
    {
        $hidden = $this->forums->create(ForumType::Forum, 'Staff', 'staff')->id;
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.view', false, $hidden);
        $readOnly = $this->forums->create(ForumType::Forum, 'News', 'news')->id;
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.thread.create', false, $readOnly);
        $category = $this->forums->create(ForumType::Category, 'Cat', 'cat')->id;
        $this->forums->setLocked($this->forum, true);
        $moderator = $this->resolver()->user($this->alice, [
            BuiltInGroup::Registered->value,
            BuiltInGroup::Moderators->value,
        ]);

        self::assertSame('thread_forum_not_found', $this->violation($this->member(), $hidden));
        self::assertSame('thread_forum_not_found', $this->violation($this->member(), 999999));
        self::assertSame('thread_permission_denied', $this->violation($this->member(), $readOnly));
        self::assertSame('thread_forum_is_category', $this->violation($this->member(), $category));
        self::assertSame('thread_forum_locked', $this->violation($this->member(), $this->forum));
        self::assertSame('thread_permission_denied', $this->violation($this->resolver()->guest(), $this->forum));
        self::assertSame(
            ThreadStatus::Visible,
            $this->service()->create($moderator, $this->forum, 'Locked', 'Body')->status,
            'moderators may post into a locked forum',
        );
    }

    public function testInvalidInputIsRejectedBeforeAnyWrite(): void
    {
        $long = str_repeat('a', CreateThread::MAX_CONTENT_LENGTH + 1);

        self::assertSame('thread_title_invalid', $this->violation($this->member(), $this->forum, " \u{3000} "));
        self::assertSame('thread_title_invalid', $this->violation($this->member(), $this->forum, "a\u{7}b"));
        self::assertSame('post_content_invalid', $this->violation($this->member(), $this->forum, 'T', "\n\t"));
        self::assertSame('post_content_invalid', $this->violation($this->member(), $this->forum, 'T', $long));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM threads'));
    }

    public function testFloodIntervalAppliesToTheUsersLastPost(): void
    {
        $this->service()->create($this->member(), $this->forum, 'First', 'Body');

        try {
            $this->service($this->now->modify('+10 seconds'))->create($this->member(), $this->forum, 'Second', 'B');
            self::fail('flood interval not applied');
        } catch (ThreadRuleViolation $violation) {
            self::assertSame(['post_flood_wait', ['seconds' => 20]], [$violation->diagnostic, $violation->parameters]);
        }

        $later = $this->service($this->now->modify('+30 seconds'))->create($this->member(), $this->forum, 'Third', 'B');

        self::assertGreaterThan(0, $later->threadId);
    }

    public function testApprovalModesHoldContentBackAndLeaveStatisticsAlone(): void
    {
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.flood_interval_seconds', 0);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.approval', 'first_posts');

        $first = $this->service()->create($this->member(), $this->forum, 'First', 'Body');
        $this->connection->update('posts', ['status' => 'visible'], ['id' => $first->postId]);
        $second = $this->service()->create($this->member(), $this->forum, 'Second', 'Body');
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.approval', 'all');
        $third = $this->service()->create($this->member(), $this->forum, 'Third', 'Body');

        self::assertSame(
            [ThreadStatus::Pending, ThreadStatus::Visible, ThreadStatus::Pending],
            [$first->status, $second->status, $third->status],
        );
        self::assertSame([1, 1, $second->postId], $this->stats(), 'only the visible thread is counted');
    }

    /**
     * Benchmark case `thread_create`: the whole service call (permission scope from cache, flood lookup, render,
     * transaction) on one connection.
     */
    public function testQueryCountAndLatency(): void
    {
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.flood_interval_seconds', 1);
        $member = $this->member();
        $content = str_repeat("[b]Bold[/b] [url=https://example.com]link[/url] text\n", 40);
        $tick = 0;
        $queries = [];
        $result = BenchmarkHarness::measure(function () use ($member, $content, &$tick, &$queries): void {
            $service = $this->service($this->now->modify('+' . (++$tick * 2) . ' seconds'));
            $this->counter->count = 0;
            $service->create($member, $this->forum, 'Benchmark thread ' . $tick, $content);
            $queries[] = $this->counter->count;
        }, 50, 5);

        fwrite(STDERR, sprintf(
            "thread_create p50 %.4f ms  p95 %.4f ms  peak %d MiB  queries %d\n",
            $result['p50_ms'],
            $result['p95_ms'],
            $result['max_peak_bytes'] / 1048576,
            max($queries),
        ));
        self::assertLessThanOrEqual(14, max($queries));
        self::assertLessThan(250.0, $result['p95_ms']);
    }

    public function testHandlerFlow(): void
    {
        (new DbalUsergroupRepository($this->connection))->addMember($this->alice, BuiltInGroup::Registered->value);
        $hidden = $this->forums->create(ForumType::Forum, 'Staff', 'staff')->id;
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.view', false, $hidden);
        $session = new ArraySession();
        $forum = ['forum' => (string) $this->forum];
        $guest = $this->handler(new ArraySession())->handle($this->request('GET'), $forum);
        $session->set('_user_id', $this->alice);
        $handler = $this->handler($session);
        $form = $handler->handle($this->request('GET'), ['forum' => (string) $this->forum]);
        $missing = $handler->handle($this->request('GET'), ['forum' => (string) $hidden]);
        $created = $handler->handle(
            $this->request('POST', ['title' => 'From the form', 'content' => 'Hello']),
            ['forum' => (string) $this->forum],
        );
        $flood = $this->handler($session)->handle(
            $this->request('POST', ['title' => '"><script>x</script>', 'content' => '<b>kept</b>']),
            ['forum' => (string) $this->forum],
        );
        $html = (string) $flood->getContent();

        self::assertSame(Response::HTTP_SEE_OTHER, $guest->getStatusCode());
        self::assertSame('/login', $guest->headers->get('Location'));
        self::assertSame(Response::HTTP_OK, $form->getStatusCode());
        self::assertStringContainsString('name="' . CsrfTokens::FIELD . '"', (string) $form->getContent());
        self::assertStringContainsString('Forum: General', (string) $form->getContent());
        self::assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode(), 'a hidden forum does not leak');
        self::assertSame(Response::HTTP_SEE_OTHER, $created->getStatusCode());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM threads'));
        self::assertSame(
            '/threads/' . $this->connection->fetchOne('SELECT id FROM threads'),
            $created->headers->get('Location'),
            'a visible new thread opens on its own page',
        );
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $flood->getStatusCode());
        self::assertSame('30', $flood->headers->get('Retry-After'));
        self::assertStringContainsString('Wait 30 seconds', $html);
        self::assertStringNotContainsString('<script>x', $html, 'the typed title is escaped');
        self::assertStringContainsString('&lt;b&gt;kept&lt;/b&gt;</textarea>', $html, 'the typed message is kept');
    }

    private function violation(
        SubjectPermissions $subject,
        int $forumId,
        string $title = 'Title',
        string $content = 'Body',
    ): string {
        try {
            $this->service()->create($subject, $forumId, $title, $content);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('no violation');
    }

    /**
     * @return array{int, int, int|null} Thread count, post count, last post ID of the test forum.
     */
    private function stats(): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT thread_count, post_count, last_post_id FROM forum_statistics WHERE forum_id = ?',
            [$this->forum],
        );
        self::assertIsArray($row);

        return [
            (int) $row['thread_count'],
            (int) $row['post_count'],
            $row['last_post_id'] === null ? null : (int) $row['last_post_id'],
        ];
    }

    private function member(): SubjectPermissions
    {
        return $this->resolver()->user($this->alice, [BuiltInGroup::Registered->value]);
    }

    public function testDraftsAreSavedRestoredAndDiscardedOnPosting(): void
    {
        (new DbalUsergroupRepository($this->connection))->addMember($this->alice, BuiltInGroup::Registered->value);
        $drafts = new DbalDrafts($this->connection);
        $session = new ArraySession();
        $session->set('_user_id', $this->alice);
        $forum = ['forum' => (string) $this->forum];

        $saved = $this->handler($session, $drafts)->handle($this->request('POST', [
            'title' => 'Half a thought',
            'content' => '<b>not yet</b>',
            'intent' => CreateThreadHandler::DRAFT,
        ]), $forum);
        self::assertSame(Response::HTTP_OK, $saved->getStatusCode());
        self::assertStringContainsString('Your draft is saved.', (string) $saved->getContent());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM threads'), 'nothing posted');

        $restored = (string) $this->handler($session, $drafts)->handle($this->request('GET'), $forum)->getContent();
        self::assertStringContainsString('Your saved draft is filled in.', $restored);
        self::assertStringContainsString('value="Half a thought"', $restored);
        self::assertStringContainsString('&lt;b&gt;not yet&lt;/b&gt;', $restored, 'escaped');

        $bob = (new DbalUserRepository($this->connection))
            ->create('Bob', 'bob@example.com', 'h', UserStatus::Active)->id;
        self::assertNull($drafts->find($bob, DraftContext::thread($this->forum)), 'drafts are per member');

        $tooLong = $this->handler($session, $drafts)->handle($this->request('POST', [
            'title' => 'x',
            'content' => str_repeat('a', Drafts::MAX_CONTENT + 1),
            'intent' => CreateThreadHandler::DRAFT,
        ]), $forum);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $tooLong->getStatusCode());
        $kept = $drafts->find($this->alice, DraftContext::thread($this->forum));
        self::assertSame('Half a thought', $kept['title'] ?? null);

        $posted = $this->handler($session, $drafts)->handle(
            $this->request('POST', ['title' => 'Whole thought', 'content' => 'Done']),
            $forum,
        );
        self::assertSame(Response::HTTP_SEE_OTHER, $posted->getStatusCode());
        self::assertNull($drafts->find($this->alice, DraftContext::thread($this->forum)), 'sending removes it');
    }

    public function testPostingHooksPreserveInvalidInputAndRevalidateReplacement(): void
    {
        $member = $this->member();
        $calls = 0;
        $hooks = new BeforeHooks();
        $hooks->add('acme/hello', static function (PostingInput $input) use (&$calls): HookDecision {
            ++$calls;

            return HookDecision::replace(str_repeat('x', CreateThread::MAX_CONTENT_LENGTH + 1));
        });
        $service = $this->service(before: $hooks);
        foreach (['', 'valid original body'] as $content) {
            try {
                $service->create($member, $this->forum, 'Valid title', $content);
                self::fail('invalid core or replacement content was written');
            } catch (ThreadRuleViolation $failure) {
                self::assertSame('post_content_invalid', $failure->diagnostic);
            }
        }

        self::assertSame(1, $calls, 'an invalid original body never reaches extension callbacks');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM posts'));
    }

    private function service(?DateTimeImmutable $now = null, ?BeforeHooks $before = null): CreateThread
    {
        $at = $now ?? $this->now;
        $posts = new DbalPostRepository($this->connection);
        $writer = new DbalThreadWriter(
            $this->connection,
            new DbalThreadRepository($this->connection),
            new Outbox($this->connection),
            new PostingLock($this->connection),
        );

        return new CreateThread(
            $this->forums,
            $this->visibility(),
            $posts,
            $writer,
            clock: static fn(): DateTimeImmutable => $at,
            before: $before,
        );
    }

    private function handler(ArraySession $session, ?Drafts $drafts = null): CreateThreadHandler
    {
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $csrf = new CsrfTokens($session);

        return new CreateThreadHandler(
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
            $drafts,
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private function request(string $method, array $fields = []): Request
    {
        return Request::create('/forums/' . $this->forum . '/new-thread', $method, $fields);
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
