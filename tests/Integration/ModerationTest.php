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
use Nolviko\Modules\Admin\Infrastructure\ForumModeratorAssignments;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Domain\Post;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\CreateReply;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ThreadListItem;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Threads\Http\ModerationHandler;
use Nolviko\Modules\Threads\Infrastructure\DbalModerationWriter;
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
 * M03.07 on MySQL 8.4: soft-deleted posts and threads disappear for members and stay listed for moderators with
 * their status as the marker; restore brings them back; every action leaves an audit entry with actor, target
 * and reason; hard delete is for administrators only. After every action the stored thread and forum counters
 * are compared with counts computed from scratch.
 */
final class ModerationTest extends TestCase
{
    private Connection $connection;

    private QueryCounter $counter;

    private DbalPostRepository $posts;

    private DbalThreadRepository $threads;

    private DbalPermissionRuleStore $rules;

    private TemporaryDirectory $storage;

    /** @var array<string, int> User IDs by name. */
    private array $users = [];

    private int $forum;

    private int $thread;

    private int $first;

    private int $bobReply;

    private int $carolReply;

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
        $this->posts = new DbalPostRepository($this->connection);
        $this->threads = new DbalThreadRepository($this->connection);
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $this->storage = new TemporaryDirectory();
        $accounts = new DbalUserRepository($this->connection);

        foreach (['alice', 'bob', 'carol', 'mod', 'admin'] as $name) {
            $this->users[$name] = $accounts->create($name, $name . '@example.com', 'h', UserStatus::Active)->id;
        }

        $this->forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'General', 'general')->id;
        [$this->thread, $this->first] = $this->startThread('alice');
        $this->bobReply = $this->reply('bob', $this->thread);
        $this->carolReply = $this->reply('carol', $this->thread);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testSoftDeleteHidesFromMembersAndRestoreBringsBack(): void
    {
        $this->service()->post($this->as('mod'), $this->bobReply, ModerateContent::DELETE, ' spam ');

        self::assertSame([$this->first, $this->carolReply], $this->postIds(false));
        self::assertSame([$this->first, $this->bobReply, $this->carolReply], $this->postIds(true));
        self::assertSame(PostStatus::Hidden, $this->posts->find($this->bobReply)?->status, 'the marker');
        self::assertTrue(ModerateContent::seesDeleted($this->as('mod'), $this->forum));
        self::assertFalse(ModerateContent::seesDeleted($this->as('bob'), $this->forum));
        self::assertSame('post_not_found', $this->postViolation('carol', $this->bobReply, ModerateContent::DELETE));
        self::assertSame([1, 2, $this->carolReply], $this->counters());
        $this->assertCountersExact();

        $this->service()->post($this->as('mod'), $this->bobReply, ModerateContent::RESTORE, 'appeal');

        self::assertSame([$this->first, $this->bobReply, $this->carolReply], $this->postIds(false));
        self::assertSame([2, 3, $this->carolReply], $this->counters());
        $this->assertCountersExact();
        self::assertSame(
            [
                ['post.delete', $this->users['mod'], 'post', $this->bobReply, 'spam'],
                ['post.restore', $this->users['mod'], 'post', $this->bobReply, 'appeal'],
            ],
            $this->audit(),
        );
        self::assertSame(
            ['post.deleted', 'post.restored'],
            $this->connection->fetchFirstColumn(
                "SELECT event_type FROM outbox_events WHERE event_type LIKE 'post.%ed' "
                    . "AND event_type <> 'post.created' ORDER BY id",
            ),
        );
    }

    public function testDeletingTheLastReplyMovesTheLastPostBack(): void
    {
        $this->service()->post($this->as('mod'), $this->carolReply, ModerateContent::DELETE);

        self::assertSame([1, 2, $this->bobReply], $this->counters());
        self::assertSame($this->bobReply, (int) $this->forumStats()['last_post_id']);
        $this->assertCountersExact();

        $this->service()->post($this->as('mod'), $this->carolReply, ModerateContent::RESTORE);

        self::assertSame([2, 3, $this->carolReply], $this->counters());
        $this->assertCountersExact();
    }

    public function testThreadDeleteAndRestoreKeepForumCountersExact(): void
    {
        [$other] = $this->startThread('bob');
        $this->reply('alice', $other);
        $this->service()->thread($this->as('mod'), $this->thread, ModerateContent::DELETE, 'off topic');

        $member = $this->threads->forumThreads(new ForumScope([$this->forum]), $this->forum, 20);
        $moderator = $this->threads->forumThreads(new ForumScope([$this->forum]), $this->forum, 20, true);
        $statuses = array_map(static fn(ThreadListItem $item): ThreadStatus => $item->status, $moderator);

        self::assertSame([$other], array_map(static fn(ThreadListItem $item): int => $item->id, $member));
        self::assertContains(ThreadStatus::Hidden, $statuses, 'moderators see the deleted thread, marked');
        self::assertSame([1, 2], array_slice($this->forumCounts(), 0, 2));
        self::assertSame('thread_not_found', $this->threadViolation('carol', $this->thread, ModerateContent::RESTORE));
        self::assertSame('post_not_found', $this->postViolation('bob', $this->bobReply, ModerateContent::DELETE));
        $this->assertCountersExact();

        $this->service()->thread($this->as('mod'), $this->thread, ModerateContent::RESTORE);

        self::assertSame([2, 5], array_slice($this->forumCounts(), 0, 2));
        $this->assertCountersExact();
    }

    public function testAuthorsMayDeleteTheirOwnRepliesOnly(): void
    {
        self::assertSame('post_is_first', $this->postViolation('alice', $this->first, ModerateContent::DELETE));
        self::assertSame('moderation_denied', $this->postViolation('bob', $this->carolReply, ModerateContent::DELETE));
        self::assertSame('moderation_denied', $this->threadViolation('alice', $this->thread, ModerateContent::DELETE));
        self::assertSame('moderation_denied', $this->postViolation('bob', $this->bobReply, ModerateContent::PURGE));

        $this->service()->post($this->as('bob'), $this->bobReply, ModerateContent::DELETE);

        self::assertSame('moderation_denied', $this->postViolation('bob', $this->bobReply, ModerateContent::RESTORE));
        self::assertSame(
            [['post.delete', $this->users['bob'], 'post', $this->bobReply, null]],
            $this->audit(),
        );

        $this->connection->update('threads', ['is_locked' => 1], ['id' => $this->thread]);
        self::assertSame('thread_locked', $this->postViolation('carol', $this->carolReply, ModerateContent::DELETE));
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, ModerateContent::DELETE_OWN, false);
        $this->connection->update('threads', ['is_locked' => 0], ['id' => $this->thread]);
        $delete = ModerateContent::DELETE;

        self::assertSame('moderation_denied', $this->postViolation('carol', $this->carolReply, $delete));
        self::assertSame('moderation_denied', $this->postViolation(null, $this->carolReply, $delete));
        $this->assertCountersExact();
    }

    public function testStateConflictsAndInvalidInput(): void
    {
        $this->service()->post($this->as('mod'), $this->bobReply, ModerateContent::DELETE);

        $delete = ModerateContent::DELETE;
        $restore = ModerateContent::RESTORE;

        self::assertSame('content_already_deleted', $this->postViolation('mod', $this->bobReply, $delete));
        self::assertSame('content_not_deleted', $this->postViolation('mod', $this->carolReply, $restore));
        self::assertSame('content_not_deleted', $this->threadViolation('mod', $this->thread, ModerateContent::RESTORE));
        self::assertSame('moderation_action_invalid', $this->postViolation('mod', $this->carolReply, 'erase'));
        self::assertSame(
            'post_reason_invalid',
            $this->postViolation('mod', $this->carolReply, ModerateContent::DELETE, str_repeat('r', 256)),
        );
        self::assertSame('post_not_found', $this->postViolation('mod', 999999, ModerateContent::DELETE));
        self::assertCount(1, $this->audit());
    }

    public function testPendingContentKeepsItsStatusAcrossDeleteAndRestore(): void
    {
        $carol = $this->users['carol'];
        $pending = $this->posts->add($this->thread, $carol, 'Wait', 'bbcode', $this->at(), PostStatus::Pending);
        $this->service()->post($this->as('mod'), $pending, ModerateContent::DELETE);
        $this->service()->post($this->as('mod'), $pending, ModerateContent::RESTORE);

        self::assertSame(PostStatus::Pending, $this->posts->find($pending)?->status);
        self::assertSame([2, 3, $this->carolReply], $this->counters());
        $this->assertCountersExact();
    }

    public function testHardDeleteIsForAdministratorsAndTheAuditEntryRemains(): void
    {
        $this->posts->edit($this->bobReply, $this->users['bob'], 'Edited', 'bbcode', null, $this->at());

        self::assertSame('moderation_denied', $this->postViolation('mod', $this->bobReply, ModerateContent::PURGE));
        self::assertSame('post_is_first', $this->postViolation('admin', $this->first, ModerateContent::PURGE));

        $this->service()->post($this->as('admin'), $this->bobReply, ModerateContent::PURGE, 'personal data');

        self::assertNull($this->posts->find($this->bobReply));
        self::assertSame([], $this->posts->revisions($this->bobReply), 'revisions go with the post');
        self::assertSame([1, 2, $this->carolReply], $this->counters());
        $this->assertCountersExact();

        [$other] = $this->startThread('bob');
        $this->service()->thread($this->as('admin'), $this->thread, ModerateContent::PURGE);

        self::assertNull($this->threads->find($this->thread));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM posts WHERE thread_id = ?', [
            $this->thread,
        ]));
        self::assertSame([1, 1], array_slice($this->forumCounts(), 0, 2));
        self::assertSame($other, (int) $this->connection->fetchOne('SELECT id FROM threads'));
        self::assertSame(
            [
                ['post.purge', $this->users['admin'], 'post', $this->bobReply, 'personal data'],
                ['thread.purge', $this->users['admin'], 'thread', $this->thread, null],
            ],
            $this->audit(),
        );
        $this->assertCountersExact();
    }

    public function testHandlerAppliesTheActionAndMapsRefusals(): void
    {
        $groups = new DbalUsergroupRepository($this->connection);
        $groups->addMember($this->users['mod'], BuiltInGroup::Registered->value);
        $groups->addMember($this->users['mod'], BuiltInGroup::Moderators->value);
        $groups->addMember($this->users['carol'], BuiltInGroup::Registered->value);
        $post = ['post' => (string) $this->bobReply];
        $form = $this->handler('mod')->handle($this->request('GET', $this->bobReply), $post);
        $refused = $this->handler('carol')->handle($this->request('POST', $this->bobReply, 'delete'), $post);
        $deleted = $this->handler('mod')->handle($this->request('POST', $this->bobReply, 'delete'), $post);
        $again = $this->handler('mod')->handle($this->request('POST', $this->bobReply, 'delete'), $post);
        $missing = $this->handler('mod')->handle($this->request('POST', 999999, 'delete'), ['post' => '999999']);
        $guest = $this->handler(null)->handle($this->request('POST', $this->bobReply, 'delete'), $post);

        self::assertSame(Response::HTTP_OK, $form->getStatusCode());
        self::assertStringContainsString('value="purge"', (string) $form->getContent());
        self::assertSame(Response::HTTP_FORBIDDEN, $refused->getStatusCode());
        self::assertSame(Response::HTTP_SEE_OTHER, $deleted->getStatusCode());
        self::assertSame(PostStatus::Hidden, $this->posts->find($this->bobReply)?->status);
        self::assertSame(Response::HTTP_CONFLICT, $again->getStatusCode());
        self::assertStringContainsString('already been deleted', (string) $again->getContent());
        self::assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
        self::assertSame('/login', $guest->headers->get('Location'));
    }

    /**
     * Benchmark case `moderation_post`: one delete or restore of a reply (permission scope from cache, locks,
     * recomputed thread counters, forum delta and last post, audit entry, outbox event).
     */
    public function testQueryCountAndLatency(): void
    {
        $moderator = $this->as('mod');
        $service = $this->service();
        $tick = 0;
        $queries = [];
        $result = BenchmarkHarness::measure(function () use ($moderator, $service, &$tick, &$queries): void {
            $action = ++$tick % 2 === 1 ? ModerateContent::DELETE : ModerateContent::RESTORE;
            $this->counter->count = 0;
            $service->post($moderator, $this->bobReply, $action, 'bench');
            $queries[] = $this->counter->count;
        }, 50, 6);

        fwrite(STDERR, sprintf(
            "moderation_post p50 %.4f ms  p95 %.4f ms  peak %d MiB  queries %d\n",
            $result['p50_ms'],
            $result['p95_ms'],
            $result['max_peak_bytes'] / 1048576,
            max($queries),
        ));
        self::assertLessThanOrEqual(24, max($queries));
        self::assertLessThan(250.0, $result['p95_ms']);
        $this->assertCountersExact();
    }

    /**
     * Stored counters against counts computed from the rows.
     */
    private function assertCountersExact(): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, first_post_id, reply_count FROM threads') as $row) {
            $replies = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM posts WHERE thread_id = ? AND status = 'visible' AND id <> ?",
                [$row['id'], (int) $row['first_post_id']],
            );
            self::assertSame($replies, (int) $row['reply_count'], 'reply_count of thread ' . $row['id']);
        }

        $expected = [
            (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM threads WHERE forum_id = ? AND status = 'visible'",
                [$this->forum],
            ),
            (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM posts p JOIN threads t ON t.id = p.thread_id WHERE t.forum_id = ? "
                    . "AND t.status = 'visible' AND p.status = 'visible'",
                [$this->forum],
            ),
        ];

        self::assertSame($expected, array_slice($this->forumCounts(), 0, 2), 'forum counters');
    }

    /**
     * @return array{int, int, int} The thread's reply count, the forum's post count, the thread's last post.
     */
    private function counters(): array
    {
        $thread = $this->connection->fetchAssociative('SELECT reply_count, last_post_id FROM threads WHERE id = ?', [
            $this->thread,
        ]);
        self::assertIsArray($thread);

        return [(int) $thread['reply_count'], $this->forumCounts()[1], (int) $thread['last_post_id']];
    }

    /**
     * @return array{int, int, int|null} Thread count, post count, last post ID.
     */
    private function forumCounts(): array
    {
        $row = $this->forumStats();

        return [
            (int) $row['thread_count'],
            (int) $row['post_count'],
            $row['last_post_id'] === null ? null : (int) $row['last_post_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function forumStats(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM forum_statistics WHERE forum_id = ?', [$this->forum]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array{string, int|null, string, int, string|null}>
     */
    private function audit(): array
    {
        return array_map(static fn(array $row): array => [
            (string) $row['action'],
            $row['actor_id'] === null ? null : (int) $row['actor_id'],
            (string) $row['target_type'],
            (int) $row['target_id'],
            $row['reason'] === null ? null : (string) $row['reason'],
        ], $this->connection->fetchAllAssociative(
            'SELECT action, actor_id, target_type, target_id, reason FROM audit_logs ORDER BY id',
        ));
    }

    /**
     * @return list<int>
     */
    private function postIds(bool $withDeleted): array
    {
        return array_map(
            static fn(Post $post): int => $post->id,
            $this->posts->threadPosts($this->thread, 50, 0, $withDeleted),
        );
    }

    private function postViolation(?string $user, int $postId, string $action, string $reason = ''): string
    {
        try {
            $subject = $user === null ? $this->resolver()->guest() : $this->as($user);
            $this->service()->post($subject, $postId, $action, $reason);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('no violation');
    }

    private function threadViolation(string $user, int $threadId, string $action): string
    {
        try {
            $this->service()->thread($this->as($user), $threadId, $action);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('no violation');
    }

    public function testForumAssignmentLimitsRealModerationToItsSubtree(): void
    {
        $forums = new DbalForumRepository($this->connection);
        $outside = $forums->create(ForumType::Forum, 'Outside', 'outside-role')->id;
        [$insideThread] = $this->startThread('alice');
        $original = $this->forum;
        $this->forum = $outside;
        [$outsideThread] = $this->startThread('alice');
        $this->forum = $original;
        $assignment = new ForumModeratorAssignments($this->connection);
        $assignment->assign($this->as('admin'), true, $original, 'alice', null);
        $moderator = $this->as('alice');
        $command = new ModerateContent(
            $this->posts,
            $this->threads,
            $this->visibility(),
            new DbalModerationWriter($this->connection, new Outbox($this->connection))
        );
        $command->thread($moderator, $insideThread, ModerateContent::DELETE, 'Local role acceptance');
        self::assertSame(ThreadStatus::Hidden->value, $this->connection->fetchOne(
            'SELECT status FROM threads WHERE id = ?',
            [$insideThread]
        ));
        try {
            $command->thread($moderator, $outsideThread, ModerateContent::DELETE, 'Must be refused');
            self::fail('local moderator changed an unassigned forum');
        } catch (ThreadRuleViolation $error) {
            self::assertSame('moderation_denied', $error->diagnostic);
        }
        self::assertSame(ThreadStatus::Visible->value, $this->connection->fetchOne(
            'SELECT status FROM threads WHERE id = ?',
            [$outsideThread]
        ));
    }

    private function as(string $user): SubjectPermissions
    {
        $groups = [BuiltInGroup::Registered->value];

        if ($user === 'mod') {
            $groups[] = BuiltInGroup::Moderators->value;
        } elseif ($user === 'admin') {
            $groups[] = BuiltInGroup::Administrators->value;
        }

        return $this->resolver()->user($this->users[$user], $groups);
    }

    /**
     * @return array{int, int} Thread ID and first post ID.
     */
    private function startThread(string $user): array
    {
        $writer = new DbalThreadWriter(
            $this->connection,
            $this->threads,
            new Outbox($this->connection),
            new PostingLock($this->connection),
        );
        $at = $this->at();
        $created = (new CreateThread(
            new DbalForumRepository($this->connection),
            $this->visibility(),
            $this->posts,
            $writer,
            clock: static fn(): DateTimeImmutable => $at,
        ))->create($this->as($user), $this->forum, 'Topic by ' . $user, 'First post');

        return [$created->threadId, $created->postId];
    }

    private function reply(string $user, int $threadId): int
    {
        $at = $this->at();
        $replies = new CreateReply(
            $this->threads,
            $this->visibility(),
            $this->posts,
            new DbalReplyWriter(
                $this->connection,
                new Outbox($this->connection),
                new PostingLock($this->connection),
            ),
            clock: static fn(): DateTimeImmutable => $at,
        );

        return $replies->reply($this->as($user), $threadId, CreateReply::newToken(), 'Reply by ' . $user)->postId;
    }

    /**
     * A fresh minute for every write, so no flood interval applies.
     */
    private function at(): DateTimeImmutable
    {
        return (new DateTimeImmutable('2026-11-01 08:00:00', new DateTimeZone('UTC')))
            ->modify('+' . (++$this->minute) . ' minutes');
    }

    private function service(): ModerateContent
    {
        $at = $this->at();

        return new ModerateContent(
            $this->posts,
            $this->threads,
            $this->visibility(),
            new DbalModerationWriter($this->connection, new Outbox($this->connection)),
            clock: static fn(): DateTimeImmutable => $at,
        );
    }

    private function handler(?string $user): ModerationHandler
    {
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $session = new ArraySession();

        if ($user !== null) {
            $session->set('_user_id', $this->users[$user]);
        }

        $csrf = new CsrfTokens($session);

        return new ModerationHandler(
            ModerationHandler::POST,
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

    private function request(string $method, int $postId, string $action = ''): Request
    {
        return Request::create('/posts/' . $postId . '/moderate', $method, $action === '' ? [] : [
            'action' => $action,
        ]);
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
