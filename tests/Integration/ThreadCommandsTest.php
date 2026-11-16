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
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Http\ForumOptions;
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
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\CreateReply;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ThreadCommands;
use Nolviko\Modules\Threads\Domain\ThreadListItem;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Http\ThreadCommandHandler;
use Nolviko\Modules\Threads\Http\ThreadPostsHandler;
use Nolviko\Modules\Threads\Infrastructure\DbalModerationWriter;
use Nolviko\Modules\Threads\Infrastructure\DbalReplyWriter;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadCommandWriter;
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
 * M03.08 on MySQL 8.4: sticky, lock and move are moderator-only and audited; a move updates both forums'
 * statistics exactly and can leave a redirect stub that is listed but never counted and takes no replies.
 */
final class ThreadCommandsTest extends TestCase
{
    private Connection $connection;

    private QueryCounter $counter;

    private DbalPostRepository $posts;

    private DbalThreadRepository $threads;

    private DbalForumRepository $forums;

    private DbalPermissionRuleStore $rules;

    private TemporaryDirectory $storage;

    /** @var array<string, int> */
    private array $users = [];

    private int $source;

    private int $target;

    private int $thread;

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
        $this->forums = new DbalForumRepository($this->connection);
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $this->storage = new TemporaryDirectory();
        $accounts = new DbalUserRepository($this->connection);

        foreach (['alice', 'bob', 'mod'] as $name) {
            $this->users[$name] = $accounts->create($name, $name . '@example.com', 'h', UserStatus::Active)->id;
        }

        $this->source = $this->forums->create(ForumType::Forum, 'Source', 'source')->id;
        $this->target = $this->forums->create(ForumType::Forum, 'Target', 'target')->id;
        $this->thread = $this->startThread('alice', $this->source);
        $this->reply('bob', $this->thread);
        $this->reply('alice', $this->thread);
        $this->startThread('bob', $this->target);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testStickyAndLockAreSetClearedAndAudited(): void
    {
        $this->command('mod', ThreadCommands::STICKY);
        self::assertSame('thread_state_unchanged', $this->violation('mod', ThreadCommands::STICKY));
        $this->command('mod', ThreadCommands::LOCK, reason: 'heated');

        self::assertTrue($this->threads->find($this->thread)?->isSticky);
        self::assertTrue($this->threads->find($this->thread)?->isLocked);
        self::assertSame('thread_locked', $this->replyViolation('bob'));

        $this->command('mod', ThreadCommands::UNLOCK);
        $this->command('mod', ThreadCommands::UNSTICKY);

        self::assertFalse($this->threads->find($this->thread)?->isSticky);
        self::assertSame(
            [
                ['thread.sticky', null],
                ['thread.lock', 'heated'],
                ['thread.unlock', null],
                ['thread.unsticky', null],
            ],
            array_map(static fn(array $row): array => [$row['action'], $row['reason']], $this->audit()),
        );
        self::assertSame(
            ['thread.stickied', 'thread.locked', 'thread.unlocked', 'thread.unstickied'],
            $this->connection->fetchFirstColumn(
                "SELECT event_type FROM outbox_events WHERE event_type NOT IN ('thread.created', 'post.created') "
                    . 'ORDER BY id',
            ),
        );
    }

    public function testMembersAndGuestsAreRefused(): void
    {
        $staff = $this->forums->create(ForumType::Forum, 'Staff', 'staff')->id;
        $hidden = $this->startThread('alice', $staff);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.view', false, $staff);

        foreach (ThreadCommands::COMMANDS as $command) {
            self::assertSame('moderation_denied', $this->violation('alice', $command, $this->target), $command);
        }

        self::assertSame('moderation_denied', $this->violation(null, ThreadCommands::LOCK));
        self::assertSame('moderation_action_invalid', $this->violation('mod', 'pin'));
        self::assertSame('thread_not_found', $this->violation('alice', ThreadCommands::LOCK, null, $hidden));
        self::assertSame('thread_not_found', $this->violation('mod', ThreadCommands::LOCK, null, 999999));
        self::assertSame([], $this->audit());
    }

    public function testMoveUpdatesBothForumsAndLeavesAStub(): void
    {
        self::assertSame([[1, 3], [1, 1]], [$this->counts($this->source), $this->counts($this->target)]);

        $stub = $this->command('mod', ThreadCommands::MOVE, $this->target, true, 'wrong forum');

        self::assertNotNull($stub);
        self::assertSame($this->target, $this->threads->find($this->thread)?->forumId);
        self::assertSame([[0, 0], [2, 4]], [$this->counts($this->source), $this->counts($this->target)]);
        self::assertNull($this->stats($this->source)['last_post_id'], 'only the stub is left, and it has no posts');
        self::assertSame(
            (int) $this->connection->fetchOne('SELECT MAX(id) FROM posts'),
            (int) $this->stats($this->target)['last_post_id'],
            'the newest post of either thread now in the target forum',
        );
        $this->assertCountersExact();

        $listed = $this->threads->forumThreads(new ForumScope([$this->source]), $this->source, 20);

        self::assertSame(
            [[$stub, $this->thread]],
            array_map(static fn(ThreadListItem $item): array => [$item->id, $item->movedToThreadId], $listed),
        );
        self::assertSame('thread_not_found', $this->replyViolation('bob', $stub));
        self::assertSame('thread_is_redirect', $this->violation('mod', ThreadCommands::LOCK, null, $stub));
        self::assertSame(
            ['thread.move', 'wrong forum', $this->source, $this->target, $stub],
            [
                $this->audit()[0]['action'],
                $this->audit()[0]['reason'],
                $this->audit()[0]['details']['from_forum_id'],
                $this->audit()[0]['details']['to_forum_id'],
                $this->audit()[0]['details']['stub_thread_id'],
            ],
        );

        $this->moderation()->thread($this->as('admin'), $this->thread, ModerateContent::PURGE);

        self::assertNull($this->threads->find($stub), 'purging the thread removes its stubs');
        $this->assertCountersExact();
    }

    public function testSplitMoveAndMergeKeepThreadsAndCountersExact(): void
    {
        $posts = array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM posts WHERE thread_id = ? ORDER BY id',
            [$this->thread],
        ));
        $other = (int) $this->connection->fetchOne('SELECT id FROM threads WHERE forum_id = ?', [$this->target]);
        $commands = $this->commands();
        $refused = function (string $user, array $ids, bool $all, ?int $thread, ?int $forum) use ($commands): string {
            try {
                $commands->movePosts($this->as($user), $this->thread, $ids, $all, $thread, $forum, 'Split off');
            } catch (ThreadRuleViolation $violation) {
                return $violation->diagnostic;
            }

            return 'accepted';
        };

        self::assertSame('moderation_denied', $refused('bob', [$posts[2]], false, null, $this->target));
        self::assertSame('thread_posts_invalid', $refused('mod', [], false, null, $this->target));
        self::assertSame('thread_merge_target_invalid', $refused('mod', [$posts[2]], false, $this->thread, null));

        $split = $commands->movePosts(
            $this->as('mod'),
            $this->thread,
            [$posts[2]],
            false,
            null,
            $this->target,
            'Split off',
        );
        $new = $this->threads->find($split);
        self::assertNotNull($new);
        self::assertSame([$this->target, 'Split off', $posts[2], 0, $this->users['alice']], [$new->forumId,
            $new->title, $new->firstPostId, $new->replyCount, $new->userId]);
        self::assertSame(1, $this->threads->find($this->thread)?->replyCount);
        $this->assertCountersExact();

        self::assertSame('thread_posts_all', $refused('mod', [$posts[0], $posts[1]], false, $other, null));
        $commands->movePosts($this->as('mod'), $this->thread, [$posts[1]], false, $other, null, '');
        self::assertSame($other, $this->posts->find($posts[1])?->threadId);
        $this->assertCountersExact();

        $commands->movePosts($this->as('mod'), $this->thread, [], true, $other, null, '', 'duplicate');
        self::assertSame($other, $this->threads->find($this->thread)?->movedToThreadId, 'the old thread redirects');
        self::assertSame($posts[0], $this->threads->find($other)?->firstPostId, 'the older first post leads');
        self::assertSame(2, $this->threads->find($other)?->replyCount);
        $this->assertCountersExact();
        self::assertSame(3, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'posts.move'",
        ));
        self::assertSame(12, ThreadPostsHandler::threadId('https://forum.example/threads/12?page=2#post-4'));
        self::assertSame(7, ThreadPostsHandler::threadId(' 7 '));
        self::assertNull(ThreadPostsHandler::threadId('/forums/3'));
    }

    public function testForumPickerListsOnlyReadableForumsInTreeOrder(): void
    {
        $category = $this->forums->create(ForumType::Category, 'Hall', 'hall')->id;
        $child = $this->forums->create(ForumType::Forum, 'Child', 'child', $category)->id;
        $options = ForumOptions::readable($this->forums, new ForumScope([$category, $child, $this->target]));
        $byId = array_column($options, null, 'id');

        self::assertArrayNotHasKey($this->source, $byId);
        self::assertFalse($byId[$category]['selectable']);
        self::assertTrue($byId[$child]['selectable']);
        self::assertSame("\u{2003}Child", $byId[$child]['label']);
        self::assertLessThan(
            array_search($child, array_column($options, 'id'), true),
            array_search($category, array_column($options, 'id'), true),
        );
    }

    public function testMoveRulesAndADeletedThreadCountsNowhere(): void
    {
        $category = $this->forums->create(ForumType::Category, 'Cat', 'cat')->id;
        $closed = $this->forums->create(ForumType::Forum, 'Closed', 'closed')->id;
        $this->rules->setGroupRule(BuiltInGroup::Moderators->value, 'moderation.thread.move', false, $closed);

        self::assertSame('thread_move_same_forum', $this->violation('mod', ThreadCommands::MOVE, $this->source));
        self::assertSame('thread_move_target_invalid', $this->violation('mod', ThreadCommands::MOVE, $category));
        self::assertSame('thread_move_target_invalid', $this->violation('mod', ThreadCommands::MOVE, $closed));
        self::assertSame('thread_move_target_invalid', $this->violation('mod', ThreadCommands::MOVE, 999999));
        self::assertSame('thread_move_target_invalid', $this->violation('mod', ThreadCommands::MOVE));

        $this->moderation()->thread($this->as('mod'), $this->thread, ModerateContent::DELETE);
        self::assertNull($this->command('mod', ThreadCommands::MOVE, $this->target));
        self::assertSame([[0, 0], [1, 1]], [$this->counts($this->source), $this->counts($this->target)]);

        $this->moderation()->thread($this->as('mod'), $this->thread, ModerateContent::RESTORE);
        self::assertSame([[0, 0], [2, 4]], [$this->counts($this->source), $this->counts($this->target)]);
        $this->assertCountersExact();
    }

    public function testHandlerIsModeratorOnly(): void
    {
        $groups = new DbalUsergroupRepository($this->connection);
        $groups->addMember($this->users['alice'], BuiltInGroup::Registered->value);
        $groups->addMember($this->users['mod'], BuiltInGroup::Registered->value);
        $groups->addMember($this->users['mod'], BuiltInGroup::Moderators->value);
        $thread = ['thread' => (string) $this->thread];
        $form = $this->handler('mod')->handle($this->request('GET'), $thread);
        $member = $this->handler('alice')->handle($this->request('POST', ['command' => 'lock']), $thread);
        $locked = $this->handler('mod')->handle($this->request('POST', ['command' => 'lock']), $thread);
        $again = $this->handler('mod')->handle($this->request('POST', ['command' => 'lock']), $thread);
        $moved = $this->handler('mod')->handle(
            $this->request('POST', ['command' => 'move', 'forum' => (string) $this->target, 'stub' => '1']),
            $thread,
        );
        $missing = $this->handler('mod')->handle($this->request('POST', ['command' => 'lock']), ['thread' => '999999']);
        $guest = $this->handler(null)->handle($this->request('POST', ['command' => 'lock']), $thread);

        self::assertSame(Response::HTTP_OK, $form->getStatusCode());
        self::assertStringContainsString('value="move"', (string) $form->getContent());
        self::assertSame(Response::HTTP_FORBIDDEN, $member->getStatusCode());
        self::assertSame(Response::HTTP_SEE_OTHER, $locked->getStatusCode());
        self::assertSame(Response::HTTP_CONFLICT, $again->getStatusCode());
        self::assertSame(Response::HTTP_SEE_OTHER, $moved->getStatusCode());
        self::assertSame($this->target, $this->threads->find($this->thread)?->forumId);
        self::assertSame(Response::HTTP_NOT_FOUND, $missing->getStatusCode());
        self::assertSame('/login', $guest->headers->get('Location'));
    }

    /**
     * Benchmark case `thread_move`: one move with a stub (permission scope from cache, thread and both statistics
     * rows locked, contribution, two forum refreshes, stub, audit entry, outbox event).
     */
    public function testQueryCountAndLatency(): void
    {
        $moderator = $this->as('mod');
        $commands = $this->commands();
        $forums = [$this->target, $this->source];
        $tick = 0;
        $queries = [];
        $result = BenchmarkHarness::measure(function () use ($moderator, $commands, $forums, &$tick, &$queries): void {
            $this->counter->count = 0;
            $commands->run($moderator, $this->thread, ThreadCommands::MOVE, $forums[$tick++ % 2], true);
            $queries[] = $this->counter->count;
        }, 50, 6);

        fwrite(STDERR, sprintf(
            "thread_move p50 %.4f ms  p95 %.4f ms  peak %d MiB  queries %d\n",
            $result['p50_ms'],
            $result['p95_ms'],
            $result['max_peak_bytes'] / 1048576,
            max($queries),
        ));
        self::assertLessThanOrEqual(26, max($queries));
        self::assertLessThan(250.0, $result['p95_ms']);
        $this->assertCountersExact();
    }

    /**
     * Every forum's stored counters against counts from the rows (stubs are never counted).
     */
    private function assertCountersExact(): void
    {
        foreach ($this->connection->fetchFirstColumn('SELECT forum_id FROM forum_statistics') as $forumId) {
            $expected = [
                (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM threads WHERE forum_id = ? AND status = 'visible' "
                        . 'AND moved_to_thread_id IS NULL',
                    [$forumId],
                ),
                (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM posts p JOIN threads t ON t.id = p.thread_id WHERE t.forum_id = ? '
                        . "AND t.status = 'visible' AND t.moved_to_thread_id IS NULL AND p.status = 'visible'",
                    [$forumId],
                ),
            ];
            self::assertSame($expected, $this->counts((int) $forumId), 'forum ' . $forumId);
        }
    }

    /**
     * @return array{int, int} Thread and post count.
     */
    private function counts(int $forumId): array
    {
        $row = $this->stats($forumId);

        return [(int) $row['thread_count'], (int) $row['post_count']];
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(int $forumId): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM forum_statistics WHERE forum_id = ?', [$forumId]);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<array{action: string, reason: string|null, details: array<string, mixed>}>
     */
    private function audit(): array
    {
        return array_map(static fn(array $row): array => [
            'action' => (string) $row['action'],
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
            'details' => (array) json_decode((string) $row['details'], true),
        ], $this->connection->fetchAllAssociative('SELECT action, reason, details FROM audit_logs ORDER BY id'));
    }

    private function command(
        string $user,
        string $command,
        ?int $target = null,
        bool $stub = false,
        string $reason = '',
    ): ?int {
        return $this->commands()->run($this->as($user), $this->thread, $command, $target, $stub, $reason);
    }

    private function violation(?string $user, string $command, ?int $target = null, ?int $threadId = null): string
    {
        try {
            $subject = $user === null ? $this->resolver()->guest() : $this->as($user);
            $this->commands()->run($subject, $threadId ?? $this->thread, $command, $target);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('no violation');
    }

    private function replyViolation(string $user, ?int $threadId = null): string
    {
        try {
            $this->reply($user, $threadId ?? $this->thread);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('no violation');
    }

    private function as(string $user): SubjectPermissions
    {
        $groups = [BuiltInGroup::Registered->value];

        if ($user === 'mod') {
            $groups[] = BuiltInGroup::Moderators->value;
        } elseif ($user === 'admin') {
            $groups[] = BuiltInGroup::Administrators->value;

            return $this->resolver()->user($this->users['mod'], $groups);
        }

        return $this->resolver()->user($this->users[$user], $groups);
    }

    private function startThread(string $user, int $forumId): int
    {
        $at = $this->at();

        return (new CreateThread(
            $this->forums,
            $this->visibility(),
            $this->posts,
            new DbalThreadWriter(
                $this->connection,
                $this->threads,
                new Outbox($this->connection),
                new PostingLock($this->connection),
            ),
            clock: static fn(): DateTimeImmutable => $at,
        ))->create($this->as($user), $forumId, 'Topic by ' . $user, 'First post')->threadId;
    }

    private function reply(string $user, int $threadId): int
    {
        $at = $this->at();

        return (new CreateReply(
            $this->threads,
            $this->visibility(),
            $this->posts,
            new DbalReplyWriter(
                $this->connection,
                new Outbox($this->connection),
                new PostingLock($this->connection),
            ),
            clock: static fn(): DateTimeImmutable => $at,
        ))->reply($this->as($user), $threadId, CreateReply::newToken(), 'Reply by ' . $user)->postId;
    }

    private function at(): DateTimeImmutable
    {
        return (new DateTimeImmutable('2026-11-01 08:00:00', new DateTimeZone('UTC')))
            ->modify('+' . (++$this->minute) . ' minutes');
    }

    private function commands(): ThreadCommands
    {
        $at = $this->at();

        return new ThreadCommands(
            $this->threads,
            $this->forums,
            $this->visibility(),
            new DbalThreadCommandWriter($this->connection, new Outbox($this->connection)),
            clock: static fn(): DateTimeImmutable => $at,
        );
    }

    private function moderation(): ModerateContent
    {
        return new ModerateContent(
            $this->posts,
            $this->threads,
            $this->visibility(),
            new DbalModerationWriter($this->connection, new Outbox($this->connection)),
        );
    }

    private function handler(?string $user): ThreadCommandHandler
    {
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $session = new ArraySession();

        if ($user !== null) {
            $session->set('_user_id', $this->users[$user]);
        }

        $csrf = new CsrfTokens($session);

        return new ThreadCommandHandler(
            $translator,
            TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-no-storage', $translator),
            new RequestSubject(
                new AuthSession($session, $csrf, new MemoryUsers(), new ResponseCookies()),
                new DbalUsergroupRepository($this->connection),
                $this->resolver(),
            ),
            $this->commands(),
            $csrf,
            new Router(CompiledRoutes::compile(CoreRoutes::definitions())),
            new ErrorResponder($translator),
        );
    }

    /**
     * @param array<string, string> $fields
     */
    private function request(string $method, array $fields = []): Request
    {
        return Request::create('/threads/' . $this->thread . '/manage', $method, $fields);
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
