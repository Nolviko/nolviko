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
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\CreateReply;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\NewReply;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Http\CreateReplyHandler;
use Nolviko\Modules\Threads\Http\ThreadPageHandler;
use Nolviko\Modules\Threads\Infrastructure\DbalReplyWriter;
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
 * M03.05 on MySQL 8.4: a reply updates thread and forum counters and the outbox in one transaction; a repeated
 * form token creates one post, also when the submits race; locked, hidden and pending threads are refused; and
 * 20 parallel replies keep every count exact while the flood interval holds per user.
 */
final class CreateReplyTest extends TestCase
{
    private Connection $connection;

    private QueryCounter $counter;

    private DbalForumRepository $forums;

    private DbalPermissionRuleStore $rules;

    private TemporaryDirectory $storage;

    private int $alice;

    private int $forum;

    private int $thread;

    private DateTimeImmutable $now;

    private int $started = 0;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->counter = new QueryCounter();
        $this->connection = self::connect([$this->counter]);
        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        TestDatabase::reset($this->connection);
        $this->forums = new DbalForumRepository($this->connection);
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $this->storage = new TemporaryDirectory();
        $this->alice = $this->user('alice');
        $this->forum = $this->forums->create(ForumType::Forum, 'General', 'general')->id;
        $this->now = new DateTimeImmutable('2026-11-01 12:00:00', new DateTimeZone('UTC'));
        $this->thread = $this->startThread($this->forum);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testReplyUpdatesThreadForumAndOutboxTogether(): void
    {
        $bob = $this->user('bob');
        $token = CreateReply::newToken();
        $reply = $this->service()->reply($this->member($bob), $this->thread, $token, '[i]Hi[/i]');

        $thread = $this->connection->fetchAssociative('SELECT * FROM threads WHERE id = ?', [$this->thread]);
        $post = $this->connection->fetchAssociative('SELECT * FROM posts WHERE id = ?', [$reply->postId]);
        $key = $this->connection->fetchAssociative('SELECT * FROM idempotency_keys');
        $event = $this->connection->fetchAssociative("SELECT * FROM outbox_events WHERE event_type = 'post.created'");

        self::assertIsArray($thread);
        self::assertIsArray($post);
        self::assertIsArray($key);
        self::assertIsArray($event);
        self::assertSame([PostStatus::Visible, false], [$reply->status, $reply->repeated]);
        self::assertSame(
            [1, $reply->postId, $bob],
            [(int) $thread['reply_count'], (int) $thread['last_post_id'], (int) $thread['last_poster_id']],
        );
        self::assertNotSame((int) $thread['first_post_id'], $reply->postId);
        self::assertSame((new BbcodeRenderer())->render('[i]Hi[/i]'), $post['rendered_html']);
        self::assertSame([$token, $reply->postId], [$key['token'], (int) $key['result_id']]);
        self::assertSame($reply->postId, (int) $event['aggregate_id']);
        self::assertSame([1, 2, $reply->postId], $this->stats());
    }

    public function testTheSameTokenTwiceCreatesOnePost(): void
    {
        $token = CreateReply::newToken();
        $first = $this->service()->reply($this->member(), $this->thread, $token, 'Once');
        $later = $this->service($this->now->modify('+1 second'));
        $second = $later->reply($this->member(), $this->thread, $token, 'Once');

        self::assertSame([false, true], [$first->repeated, $second->repeated]);
        self::assertSame($first->postId, $second->postId);
        self::assertSame(2, $this->postCount(), 'first post and one reply');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT reply_count FROM threads'));
        self::assertSame(
            1,
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM outbox_events WHERE event_type = 'post.created'"),
        );
    }

    public function testLockedHiddenPendingAndForbiddenThreadsAreRefused(): void
    {
        $hidden = $this->forums->create(ForumType::Forum, 'Staff', 'staff')->id;
        $hiddenThread = $this->startThread($hidden);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.view', false, $hidden);
        $noReply = $this->forums->create(ForumType::Forum, 'News', 'news')->id;
        $newsThread = $this->startThread($noReply);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.reply', false, $noReply);
        $pending = $this->startThread($this->forum);
        $this->connection->update('threads', ['status' => 'pending'], ['id' => $pending]);
        $this->connection->update('threads', ['is_locked' => 1], ['id' => $this->thread]);
        $moderator = $this->resolver()->user($this->alice, [
            BuiltInGroup::Registered->value,
            BuiltInGroup::Moderators->value,
        ]);

        self::assertSame('thread_not_found', $this->violation($this->member(), $hiddenThread));
        self::assertSame('thread_not_found', $this->violation($this->member(), $pending));
        self::assertSame('thread_not_found', $this->violation($this->member(), 999999));
        self::assertSame('reply_permission_denied', $this->violation($this->member(), $newsThread));
        self::assertSame('reply_permission_denied', $this->violation($this->resolver()->guest(), $this->thread));
        self::assertSame('thread_locked', $this->violation($this->member(), $this->thread));
        self::assertSame('post_token_invalid', $this->violation($moderator, $this->thread, 'not-a-token'));
        self::assertSame('post_content_invalid', $this->violation($moderator, $this->thread, null, ' '));
        self::assertFalse(
            $this->service()->reply($moderator, $this->thread, CreateReply::newToken(), 'Mod note')->repeated,
            'moderators may reply to a locked thread',
        );
    }

    public function testALockSetAfterTheCheckIsSeenUnderTheRowLock(): void
    {
        $this->connection->update('threads', ['is_locked' => 1], ['id' => $this->thread]);

        try {
            $this->writer()->reply(new NewReply(
                $this->thread,
                $this->forum,
                $this->alice,
                CreateReply::newToken(),
                'Late',
                'bbcode',
                'Late',
                BbcodeRenderer::VERSION,
                PostStatus::Visible,
                $this->now,
                0,
                false,
            ));
            self::fail('the writer did not re-check the lock');
        } catch (ThreadRuleViolation $violation) {
            self::assertSame('thread_locked', $violation->diagnostic);
        }

        self::assertSame(1, $this->postCount());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM idempotency_keys'));
    }

    public function testChangedForumRejectsThePreviouslyAuthorizedReply(): void
    {
        try {
            $this->writer()->reply(new NewReply(
                $this->thread,
                $this->forum + 1,
                $this->alice,
                CreateReply::newToken(),
                'Late',
                'bbcode',
                'Late',
                BbcodeRenderer::VERSION,
                PostStatus::Visible,
                $this->now,
                0,
                false,
            ));
            self::fail('a changed forum accepted the old authorization');
        } catch (ThreadRuleViolation $violation) {
            self::assertSame('thread_not_found', $violation->diagnostic);
        }

        self::assertSame(1, $this->postCount());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM idempotency_keys'));
    }

    public function testPendingRepliesLeaveTheCountersAlone(): void
    {
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.approval', 'all');
        $bob = $this->member($this->user('bob'));
        $reply = $this->service()->reply($bob, $this->thread, CreateReply::newToken(), 'Wait');

        self::assertSame(PostStatus::Pending, $reply->status);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT reply_count FROM threads'));
        self::assertSame([1, 1], array_slice($this->stats(), 0, 2));
    }

    /**
     * 20 processes reply at once: 10 users with two replies each, and the default 30-second flood interval. Each
     * user's second reply must be refused, and the counters must match the stored posts exactly.
     */
    public function testTwentyParallelRepliesKeepCountsExact(): void
    {
        $workers = [];

        for ($i = 0; $i < 10; ++$i) {
            $user = $this->user('racer' . $i);
            $workers[] = [$user, CreateReply::newToken()];
            $workers[] = [$user, CreateReply::newToken()];
        }

        $results = $this->race($workers);
        $created = array_filter($results, static fn(string $line): bool => str_starts_with($line, 'created:'));
        $thread = $this->connection->fetchAssociative('SELECT reply_count, last_post_id FROM threads WHERE id = ?', [
            $this->thread,
        ]);
        $lastReply = (int) $this->connection->fetchOne('SELECT MAX(id) FROM posts');

        self::assertIsArray($thread);
        self::assertCount(10, $created, implode(', ', $results));
        self::assertCount(10, array_keys($results, 'violation:post_flood_wait', true), implode(', ', $results));
        self::assertSame(11, $this->postCount());
        self::assertSame([10, $lastReply], [(int) $thread['reply_count'], (int) $thread['last_post_id']]);
        self::assertSame([1, 11, $lastReply], $this->stats());
    }

    /**
     * M03.15: ten users posting at once from one address share the network bucket: one reply, nine refusals,
     * and no deadlock on the bucket rows.
     */
    public function testParallelRepliesFromOneNetworkShareItsBucket(): void
    {
        $workers = [];

        foreach (range(1, 10) as $i) {
            $workers[] = [$this->user('net' . $i), CreateReply::newToken(), '198.51.100.20'];
        }

        $results = $this->race($workers);
        $created = array_filter($results, static fn(string $line): bool => str_starts_with($line, 'created:'));

        self::assertCount(1, $created, implode(', ', $results));
        self::assertCount(9, array_keys($results, 'violation:post_flood_wait', true), implode(', ', $results));
    }

    public function testParallelDoubleSubmitsCreateOnePost(): void
    {
        $bob = $this->user('bob');
        $token = CreateReply::newToken();
        $results = $this->race(array_fill(0, 5, [$bob, $token]));
        $ids = array_unique(array_map(static fn(string $line): string => explode(':', $line)[1] ?? '', $results));

        $kinds = array_count_values(array_map(static fn(string $line): string => explode(':', $line)[0], $results));
        ksort($kinds);

        self::assertSame(['created' => 1, 'repeated' => 4], $kinds, implode(', ', $results));
        self::assertCount(1, $ids, implode(', ', $results));
        self::assertSame(2, $this->postCount());
    }

    public function testHandlerShowsATokenAndAcceptsADoubleSubmitOnce(): void
    {
        (new DbalUsergroupRepository($this->connection))->addMember($this->alice, BuiltInGroup::Registered->value);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.flood_interval_seconds', 0);
        $session = new ArraySession();
        $session->set('_user_id', $this->alice);
        $parameters = ['thread' => (string) $this->thread];
        $form = $this->handler($session)->handle($this->request('GET'), $parameters);
        preg_match('/name="token" value="([0-9a-f]{32})"/', (string) $form->getContent(), $match);
        $token = $match[1] ?? '';
        $fields = ['token' => $token, 'content' => 'Twice'];
        $first = $this->handler($session)->handle($this->request('POST', $fields), $parameters);
        $second = $this->handler($session)->handle($this->request('POST', $fields), $parameters);
        $guest = $this->handler(new ArraySession())->handle($this->request('GET'), $parameters);
        $this->connection->update('threads', ['is_locked' => 1], ['id' => $this->thread]);
        $locked = $this->handler($session)->handle($this->request('GET'), $parameters);

        self::assertSame(Response::HTTP_OK, $form->getStatusCode());
        self::assertNotSame('', $token);
        self::assertSame([Response::HTTP_SEE_OTHER, Response::HTTP_SEE_OTHER], [
            $first->getStatusCode(),
            $second->getStatusCode(),
        ]);
        self::assertSame(2, $this->postCount());
        $slug = $this->connection->fetchOne('SELECT slug FROM threads WHERE id = ?', [$this->thread]);
        $post = $this->connection->fetchOne('SELECT last_post_id FROM threads WHERE id = ?', [$this->thread]);
        self::assertSame(
            '/threads/' . $slug . '-' . $this->thread . '#post-' . $post,
            $first->headers->get('Location'),
        );
        self::assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        self::assertSame(
            '/login?return=%2Fthreads%2F1%2Freply',
            $guest->headers->get('Location'),
            'back to the reply after signing in',
        );
        self::assertSame(Response::HTTP_FORBIDDEN, $locked->getStatusCode());
        self::assertStringContainsString('This thread is locked.', (string) $locked->getContent());
        self::assertStringNotContainsString('<textarea', (string) $locked->getContent());
    }

    public function testReplyLocationFollowsThePageBoundaryAndAnEarlierToken(): void
    {
        (new DbalUsergroupRepository($this->connection))->addMember($this->alice, BuiltInGroup::Registered->value);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.flood_interval_seconds', 0);
        $session = new ArraySession();
        $session->set('_user_id', $this->alice);
        $handler = $this->handler($session, quotes: true, perPage: 2);
        $parameters = ['thread' => (string) $this->thread];
        $first = $this->request('POST', ['token' => CreateReply::newToken(), 'content' => 'First reply']);
        $next = $this->request('POST', ['token' => CreateReply::newToken(), 'content' => 'Second reply']);
        $onFirstPage = $handler->handle($first, $parameters)->headers->get('Location');
        $onSecondPage = $handler->handle($next, $parameters)->headers->get('Location');

        self::assertMatchesRegularExpression('~^/threads/[^/]+#post-\d+$~', (string) $onFirstPage);
        self::assertMatchesRegularExpression('~^/threads/[^/]+/page-2#post-\d+$~', (string) $onSecondPage);
        self::assertSame($onFirstPage, $handler->handle($first, $parameters)->headers->get('Location'));
        self::assertSame(3, $this->postCount());
    }

    public function testQuoteLinkPrefillsOnlyVisiblePostsOfThisThread(): void
    {
        (new DbalUsergroupRepository($this->connection))->addMember($this->alice, BuiltInGroup::Registered->value);
        $session = new ArraySession();
        $session->set('_user_id', $this->alice);
        $parameters = ['thread' => (string) $this->thread];
        $first = (int) $this->connection->fetchOne('SELECT first_post_id FROM threads WHERE id = ?', [$this->thread]);
        $other = $this->startThread($this->forum);
        $foreign = (int) $this->connection->fetchOne('SELECT first_post_id FROM threads WHERE id = ?', [$other]);
        $author = (string) $this->connection->fetchOne(
            'SELECT u.username FROM posts p JOIN users u ON u.id = p.user_id WHERE p.id = ?',
            [$first],
        );
        $quote = fn(int $post): string => (string) $this->handler($session, true)->handle(
            Request::create('/threads/' . $this->thread . '/reply?quote=' . $post),
            $parameters,
        )->getContent();

        self::assertStringContainsString('>[quote=' . $author . ']', $quote($first));
        self::assertStringNotContainsString('[quote', $quote($foreign));
        $second = (int) $this->connection->fetchOne('SELECT MAX(id) FROM posts') + 1;
        $this->connection->insert('posts', ['id' => $second, 'thread_id' => $this->thread, 'user_id' => $this->alice,
            'content' => 'Second words', 'content_format' => 'bbcode', 'status' => 'visible',
            'created_at' => '2026-11-01 10:00:00.000000', 'updated_at' => '2026-11-01 10:00:00.000000']);
        $multi = fn(string $ids): string => (string) $this->handler($session, true)->handle(
            Request::create('/threads/' . $this->thread . '/reply?quote=' . $ids),
            $parameters,
        )->getContent();
        $both = $multi($second . ',' . $first . ',' . $foreign);
        self::assertSame(2, substr_count($both, '[/quote]'), 'multi-quote; the other thread is skipped');
        self::assertLessThan(strrpos($both, '[quote='), strpos($both, 'Second words'), 'given order');
        self::assertStringNotContainsString('[quote', $multi('1,,2'));
        self::assertStringNotContainsString('[quote', $multi(implode(',', range(1, 11))), 'at most ten');

        $this->connection->update('posts', ['status' => 'hidden'], ['id' => $first]);
        self::assertStringNotContainsString('[quote', $quote($first));
    }

    public function testPreviewRendersSanitizedBbcodeAndPostsNothing(): void
    {
        (new DbalUsergroupRepository($this->connection))->addMember($this->alice, BuiltInGroup::Registered->value);
        $session = new ArraySession();
        $session->set('_user_id', $this->alice);
        $parameters = ['thread' => (string) $this->thread];
        $before = $this->postCount();
        $fields = ['token' => str_repeat('a', 32), 'intent' => 'preview',
            'content' => '[b]Bold[/b] <script>x</script> [url=javascript:alert(1)]bad[/url]'];
        $preview = $this->handler($session)->handle($this->request('POST', $fields), $parameters);
        $empty = $this->handler($session)->handle(
            $this->request('POST', ['intent' => 'preview', 'content' => '  '] + $fields),
            $parameters,
        );
        $html = (string) $preview->getContent();

        self::assertSame(Response::HTTP_OK, $preview->getStatusCode());
        self::assertStringContainsString('class="card post-preview"', $html);
        self::assertStringContainsString('<strong>Bold</strong>', $html);
        self::assertStringNotContainsString('<script>x', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringContainsString('value="' . str_repeat('a', 32) . '"', $html);
        self::assertSame($before, $this->postCount());
        self::assertNotSame(Response::HTTP_OK, $empty->getStatusCode());
        self::assertStringNotContainsString('post-preview"', (string) $empty->getContent());
    }

    /**
     * Benchmark case `reply_create`: the whole service call (permission scope from cache, render, transaction
     * with user and thread row locks and the idempotency key) on one connection.
     */
    public function testQueryCountAndLatency(): void
    {
        $member = $this->member();
        $content = str_repeat("[quote]Earlier[/quote] [b]Bold[/b] reply text\n", 20);
        $tick = 0;
        $queries = [];
        $result = BenchmarkHarness::measure(function () use ($member, $content, &$tick, &$queries): void {
            $service = $this->service($this->now->modify('+' . (++$tick * 60) . ' seconds'));
            $this->counter->count = 0;
            $service->reply($member, $this->thread, CreateReply::newToken(), $content);
            $queries[] = $this->counter->count;
        }, 50, 5);

        fwrite(STDERR, sprintf(
            "reply_create p50 %.4f ms  p95 %.4f ms  peak %d MiB  queries %d\n",
            $result['p50_ms'],
            $result['p95_ms'],
            $result['max_peak_bytes'] / 1048576,
            max($queries),
        ));
        self::assertLessThanOrEqual(16, max($queries));
        self::assertLessThan(250.0, $result['p95_ms']);
    }

    /**
     * Benchmark case `hot_thread_reply` (M03.16): replies to a thread that already holds 20,000 posts cost the
     * same statements as replies to a short one (counters move by one, nothing scans the thread).
     */
    public function testRepliesToAHotThreadStayFlat(): void
    {
        $author = (int) $this->connection->fetchOne('SELECT MIN(id) FROM users');
        $values = [];

        for ($i = 1; $i <= 20000; ++$i) {
            $values[] = sprintf(
                "(%d, %d, 'Hot %d', 'bbcode', 'visible', '2026-10-01 00:00:00', '2026-10-01 00:00:00')",
                $this->thread,
                $this->forum,
                $author,
                $i,
            );

            if (count($values) === 2000) {
                $this->connection->executeStatement(
                    'INSERT INTO posts (thread_id, user_id, content, content_format, status, created_at, updated_at) '
                        . 'VALUES ' . implode(', ', $values),
                );
                $values = [];
            }
        }

        TestDatabase::analyze($this->connection, 'posts');
        $member = $this->member();
        $tick = 0;
        $queries = [];
        $result = BenchmarkHarness::measure(function () use ($member, &$tick, &$queries): void {
            $service = $this->service($this->now->modify('+' . (++$tick * 60) . ' seconds'));
            $this->counter->count = 0;
            $service->reply($member, $this->thread, CreateReply::newToken(), 'Still going ' . $tick);
            $queries[] = $this->counter->count;
        }, 30, 3);

        fwrite(STDERR, sprintf(
            "hot_thread_reply p50 %.4f ms  p95 %.4f ms  peak %d MiB  queries %d (20,000 posts in the thread)
",
            $result['p50_ms'],
            $result['p95_ms'],
            $result['max_peak_bytes'] / 1048576,
            max($queries),
        ));
        self::assertLessThanOrEqual(16, max($queries));
        self::assertLessThan(250.0, $result['p95_ms']);
        self::assertGreaterThan(20000, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM posts WHERE thread_id = ?',
            [$this->thread],
        ));
    }

    /**
     * @param list<array{0: int, 1: string, 2?: string}> $workers User ID, token and optional client IP.
     *
     * @return list<string> One result line per worker.
     */
    private function race(array $workers): array
    {
        $directory = new TemporaryDirectory();
        $go = $directory->path . '/go';
        $processes = [];

        foreach ($workers as $worker) {
            [$user, $token] = $worker;
            $command = [
                PHP_BINARY,
                __DIR__ . '/reply-worker.php',
                $go,
                $this->storage->path,
                (string) $this->thread,
                (string) $user,
                $token,
                ...array_slice($worker, 2),
            ];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        usleep(1_500_000);
        touch($go);
        $results = [];

        foreach ($processes as [$process, $pipes]) {
            $results[] = trim((string) stream_get_contents($pipes[1]));
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors);
        }

        $directory->remove();

        return $results;
    }

    private function violation(
        SubjectPermissions $subject,
        int $threadId,
        ?string $token = null,
        string $content = 'Body',
    ): string {
        try {
            $this->service()->reply($subject, $threadId, $token ?? CreateReply::newToken(), $content);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('no violation');
    }

    private function startThread(int $forumId): int
    {
        $posts = new DbalPostRepository($this->connection);
        $threads = new CreateThread(
            $this->forums,
            $this->visibility(),
            $posts,
            new DbalThreadWriter(
                $this->connection,
                new DbalThreadRepository($this->connection),
                new Outbox($this->connection),
                new PostingLock($this->connection),
            ),
            // A minute apart, so the starter's own flood interval never applies.
            clock: fn(): DateTimeImmutable => $this->now->modify('-1 day +' . (++$this->started) . ' minutes'),
        );

        return $threads->create($this->resolver()->user($this->alice, [
            BuiltInGroup::Registered->value,
            BuiltInGroup::Administrators->value,
        ]), $forumId, 'Topic ' . $forumId . '-' . random_int(1, 999999), 'First post')->threadId;
    }

    private function user(string $name): int
    {
        return (new DbalUserRepository($this->connection))
            ->create($name, $name . '@example.com', 'h', UserStatus::Active)->id;
    }

    private function postCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM posts');
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

    private function member(?int $userId = null): SubjectPermissions
    {
        return $this->resolver()->user($userId ?? $this->alice, [BuiltInGroup::Registered->value]);
    }

    private function writer(): DbalReplyWriter
    {
        return new DbalReplyWriter(
            $this->connection,
            new Outbox($this->connection),
            new PostingLock($this->connection),
        );
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
                $service->reply($member, $this->thread, CreateReply::newToken(), $content);
                self::fail('invalid core or replacement content was written');
            } catch (ThreadRuleViolation $failure) {
                self::assertSame('post_content_invalid', $failure->diagnostic);
            }
        }

        self::assertSame(1, $calls, 'an invalid original body never reaches extension callbacks');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM posts'));
    }

    private function service(?DateTimeImmutable $now = null, ?BeforeHooks $before = null): CreateReply
    {
        $at = $now ?? $this->now;

        return new CreateReply(
            new DbalThreadRepository($this->connection),
            $this->visibility(),
            new DbalPostRepository($this->connection),
            $this->writer(),
            clock: static fn(): DateTimeImmutable => $at,
            before: $before,
        );
    }

    private function handler(
        ArraySession $session,
        bool $quotes = false,
        int $perPage = ThreadPageHandler::DEFAULT_PER_PAGE,
    ): CreateReplyHandler {
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $csrf = new CsrfTokens($session);

        return new CreateReplyHandler(
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
            posts: $quotes ? new DbalPostRepository($this->connection) : null,
            users: $quotes ? new DbalUserRepository($this->connection) : null,
            threads: new DbalThreadRepository($this->connection),
            perPage: $perPage,
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private function request(string $method, array $fields = []): Request
    {
        return Request::create('/threads/' . $this->thread . '/reply', $method, $fields);
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
}
