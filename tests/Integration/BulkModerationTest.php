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
use Nolviko\Modules\Moderation\Domain\BulkModeration;
use Nolviko\Modules\Moderation\Http\BulkModerationHandler;
use Nolviko\Modules\Moderation\Infrastructure\DbalAccountApprovals;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\CreateReply;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ThreadCommands;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
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
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M04.08 on MySQL 8.4: approve, reject, delete, restore and move on single items and in bulk, each item through
 * the single-item service (permission per item, audit per item), with exact counters afterwards; account
 * approval for administrators; members and moderators without rights are refused.
 */
final class BulkModerationTest extends TestCase
{
    private Connection $connection;

    private DbalPostRepository $posts;

    private DbalThreadRepository $threads;

    private DbalForumRepository $forums;

    private DbalPermissionRuleStore $rules;

    private TemporaryDirectory $storage;

    /** @var array<string, int> */
    private array $users = [];

    private int $open;

    private int $closed;

    private int $minute = 0;

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
        $this->forums = new DbalForumRepository($this->connection);
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $this->storage = new TemporaryDirectory();
        $accounts = new DbalUserRepository($this->connection);
        $groups = new DbalUsergroupRepository($this->connection);
        $memberships = [
            'alice' => [BuiltInGroup::Registered],
            'mod' => [BuiltInGroup::Moderators, BuiltInGroup::Registered],
            'admin' => [BuiltInGroup::Administrators, BuiltInGroup::Registered],
        ];

        foreach ($memberships as $name => $list) {
            $this->users[$name] = $accounts->create($name, $name . '@example.com', 'h', UserStatus::Active)->id;

            foreach ($list as $group) {
                $groups->addMember($this->users[$name], $group->value);
            }
        }

        $this->open = $this->forums->create(ForumType::Forum, 'Open', 'open')->id;
        $this->closed = $this->forums->create(ForumType::Forum, 'Closed', 'closed')->id;
        $this->rules->setGroupRule(BuiltInGroup::Moderators->value, 'moderation.post.hide', false, $this->closed);
        $this->rules->setGroupRule(BuiltInGroup::Moderators->value, 'moderation.thread.move', false, $this->closed);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testBulkApproveAndRejectPendingPostsPerItem(): void
    {
        $thread = $this->startThread($this->open);
        $other = $this->startThread($this->closed);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.approval', 'all');
        $pending = [];

        foreach (range(1, 4) as $i) {
            $pending[] = $this->reply($thread, 'Wait ' . $i);
        }

        $foreign = $this->reply($other, 'Elsewhere');
        $results = $this->bulk()->apply($this->as('mod'), BulkModeration::POSTS, BulkModeration::APPROVE, [
            $pending[0],
            $pending[1],
            $foreign,
        ], 'looks fine');

        self::assertSame([$pending[0] => null, $pending[1] => null, $foreign => 'post_not_found'], $results);
        self::assertSame(PostStatus::Pending, $this->posts->find($foreign)?->status, 'a refused item is untouched');

        $rejected = $this->bulk()->apply($this->as('mod'), BulkModeration::POSTS, BulkModeration::REJECT, [
            $pending[2],
            $pending[3],
            $pending[0],
        ], 'spam');

        self::assertSame([$pending[2] => null, $pending[3] => null, $pending[0] => null], $rejected);
        self::assertSame(PostStatus::Hidden, $this->posts->find($pending[2])?->status);
        self::assertSame(
            'pending',
            $this->connection->fetchOne('SELECT status_before_delete FROM posts WHERE id = ?', [$pending[2]]),
            'restore would bring it back to pending',
        );
        $replies = (int) $this->connection->fetchOne('SELECT reply_count FROM threads WHERE id = ?', [$thread]);

        self::assertSame(1, $replies);
        self::assertSame(5, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action IN ('post.approve', 'post.delete')",
        ));
        $this->assertCountersExact();
    }

    public function testBulkMoveKeepsBothForumsExact(): void
    {
        $target = $this->forums->create(ForumType::Forum, 'Target', 'target')->id;
        $moved = [$this->startThread($this->open), $this->startThread($this->open)];
        $this->reply($moved[0], 'One');
        $stay = $this->startThread($this->closed);

        $results = $this->bulk()->apply(
            $this->as('mod'),
            BulkModeration::THREADS,
            BulkModeration::MOVE,
            [...$moved, $stay],
            'tidy',
            $target,
        );

        self::assertSame([$moved[0] => null, $moved[1] => null, $stay => 'moderation_denied'], $results);
        self::assertSame($target, $this->threads->find($moved[0])?->forumId);
        $this->assertCountersExact();
    }

    public function testAccountsAreApprovedOrRejectedByAdministratorsOnly(): void
    {
        $accounts = new DbalUserRepository($this->connection);
        $waiting = [];

        foreach (['w1', 'w2', 'w3'] as $name) {
            $waiting[$name] = $accounts->create($name, $name . '@example.com', 'h', UserStatus::PendingApproval)->id;
        }

        $byMod = $this->bulk()->apply(
            $this->as('mod'),
            BulkModeration::USERS,
            BulkModeration::APPROVE,
            [$waiting['w1']],
        );
        $approved = $this->bulk()->apply($this->as('admin'), BulkModeration::USERS, BulkModeration::APPROVE, [
            $waiting['w1'],
            $this->users['alice'],
        ]);
        $rejected = $this->bulk()->apply($this->as('admin'), BulkModeration::USERS, BulkModeration::REJECT, [
            $waiting['w2'],
        ], 'bot');

        self::assertSame([$waiting['w1'] => 'moderation_denied'], $byMod);
        self::assertSame([$waiting['w1'] => null, $this->users['alice'] => 'user_not_pending'], $approved);
        self::assertSame([$waiting['w2'] => null], $rejected);
        self::assertSame(UserStatus::Active, $accounts->findById($waiting['w1'])?->status);
        self::assertNull($accounts->findById($waiting['w2']), 'the rejected account is gone');
        self::assertSame(UserStatus::PendingApproval, $accounts->findById($waiting['w3'])?->status);
        self::assertSame(
            [['user.approve', $waiting['w1']], ['user.reject', $waiting['w2']]],
            array_map(
                static fn(array $row): array => [$row['action'], (int) $row['target_id']],
                $this->connection->fetchAllAssociative(
                    "SELECT action, target_id FROM audit_logs WHERE target_type = 'user' ORDER BY id",
                ),
            ),
        );
    }

    public function testRequestsRefusedAsAWhole(): void
    {
        $member = $this->as('alice');

        self::assertSame('moderation_denied', $this->violation($this->resolver()->guest(), BulkModeration::POSTS, [1]));
        self::assertSame('bulk_empty', $this->violation($member, BulkModeration::POSTS, []));
        self::assertSame('bulk_limit', $this->violation($member, BulkModeration::POSTS, range(1, 101)));
        self::assertSame('moderation_action_invalid', $this->violation($member, 'forum', [1]));

        $thread = $this->startThread($this->open);
        $memberResult = $this->bulk()->apply($member, BulkModeration::THREADS, BulkModeration::DELETE, [$thread]);

        self::assertSame([$thread => 'moderation_denied'], $memberResult, 'members cannot moderate');
    }

    public function testHandlerShowsEveryOutcome(): void
    {
        $thread = $this->startThread($this->open);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.post.approval', 'all');
        $pending = $this->reply($thread, 'Wait');
        $session = new ArraySession();
        $session->set('_user_id', $this->users['mod']);
        $groups = new DbalUsergroupRepository($this->connection);
        $groups->addMember($this->users['mod'], BuiltInGroup::Registered->value);
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $handler = new BulkModerationHandler(
            $translator,
            TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-no-storage', $translator),
            new RequestSubject(
                new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
                $groups,
                $this->resolver(),
            ),
            $this->bulk(),
            new Router(CompiledRoutes::compile(CoreRoutes::definitions())),
        );
        $response = $handler->handle(Request::create('/moderation/bulk', 'POST', [
            'target' => 'post',
            'action' => 'approve',
            'ids' => [(string) $pending, '999999'],
        ]), []);
        $html = (string) $response->getContent();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('1 of 2 applied.', $html);
        self::assertStringContainsString('Item ' . $pending . ': done', $html);
        self::assertStringContainsString('Item 999999: This post does not exist', $html);
    }

    /**
     * Stored thread and forum counters against counts from the rows.
     */
    private function assertCountersExact(): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, first_post_id, reply_count FROM threads') as $row) {
            $replies = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM posts WHERE thread_id = ? AND status = 'visible' AND id <> ?",
                [$row['id'], (int) $row['first_post_id']],
            );
            self::assertSame($replies, (int) $row['reply_count'], 'thread ' . $row['id']);
        }

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM forum_statistics') as $stats) {
            $expected = [
                (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM threads WHERE forum_id = ? AND status = 'visible' "
                        . 'AND moved_to_thread_id IS NULL',
                    [$stats['forum_id']],
                ),
                (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM posts p JOIN threads t ON t.id = p.thread_id WHERE t.forum_id = ? '
                        . "AND t.status = 'visible' AND t.moved_to_thread_id IS NULL AND p.status = 'visible'",
                    [$stats['forum_id']],
                ),
            ];
            self::assertSame($expected, [(int) $stats['thread_count'], (int) $stats['post_count']]);
        }
    }

    /**
     * @param list<int> $ids
     */
    private function violation(SubjectPermissions $subject, string $target, array $ids): string
    {
        try {
            $this->bulk()->apply($subject, $target, BulkModeration::APPROVE, $ids);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('no violation');
    }

    private function startThread(int $forumId): int
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
        ))->create($this->as('admin'), $forumId, 'Topic ' . $this->minute, 'First post')->threadId;
    }

    private function reply(int $threadId, string $content): int
    {
        $at = $this->at();

        return (new CreateReply(
            $this->threads,
            $this->visibility(),
            $this->posts,
            new DbalReplyWriter($this->connection, new Outbox($this->connection), new PostingLock($this->connection)),
            clock: static fn(): DateTimeImmutable => $at,
        ))->reply($this->as('alice'), $threadId, CreateReply::newToken(), $content)->postId;
    }

    private function bulk(): BulkModeration
    {
        return new BulkModeration(
            new ModerateContent(
                $this->posts,
                $this->threads,
                $this->visibility(),
                new DbalModerationWriter($this->connection, new Outbox($this->connection)),
            ),
            new ThreadCommands(
                $this->threads,
                $this->forums,
                $this->visibility(),
                new DbalThreadCommandWriter($this->connection, new Outbox($this->connection)),
            ),
            new DbalAccountApprovals($this->connection, new Outbox($this->connection)),
        );
    }

    private function as(string $user): SubjectPermissions
    {
        $groups = array_map(
            static fn(BuiltInGroup $group): int => $group->value,
            match ($user) {
                'mod' => [BuiltInGroup::Moderators, BuiltInGroup::Registered],
                'admin' => [BuiltInGroup::Administrators, BuiltInGroup::Registered],
                default => [BuiltInGroup::Registered],
            },
        );

        return $this->resolver()->user($this->users[$user], $groups);
    }

    private function resolver(): PermissionResolver
    {
        return new PermissionResolver(PermissionCatalog::core(), $this->rules, new DbalForumTree($this->connection));
    }

    private function visibility(): ForumVisibility
    {
        $cache = (new CacheFactory($this->storage->path))->namespace('permissions');
        $counter = new VersionCounter($this->connection);

        return new ForumVisibility($cache, static fn(): int => $counter->current(VersionCounter::PERMISSION_RULES));
    }

    private function at(): DateTimeImmutable
    {
        return (new DateTimeImmutable('2026-11-01 08:00:00', new DateTimeZone('UTC')))
            ->modify('+' . (++$this->minute) . ' minutes');
    }
}
