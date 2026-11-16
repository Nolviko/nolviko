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
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Moderation\Domain\ReportPost;
use Nolviko\Modules\Moderation\Http\ReportHandler;
use Nolviko\Modules\Moderation\Infrastructure\DbalReportWriter;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Domain\PostRenderer;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Http\ThreadPageHandler;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
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
 * M04.07a on MySQL 8.4: members report posts they can read, once per post while the report is open and at most
 * one report per 30 seconds; posts they cannot read are not found (IDOR); nothing about a report reaches the
 * author's pages.
 */
final class ReportTest extends TestCase
{
    private Connection $connection;

    private DbalPostRepository $posts;

    private DbalThreadRepository $threads;

    private DbalPermissionRuleStore $rules;

    private TemporaryDirectory $storage;

    /** @var array<string, int> */
    private array $users = [];

    private int $forum;

    private int $thread;

    private int $first;

    private int $second;

    private DateTimeImmutable $start;

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
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $this->storage = new TemporaryDirectory();
        $accounts = new DbalUserRepository($this->connection);
        $groups = new DbalUsergroupRepository($this->connection);

        foreach (['alice', 'bob'] as $name) {
            $this->users[$name] = $accounts->create($name, $name . '@example.com', 'h', UserStatus::Active)->id;
            $groups->addMember($this->users[$name], BuiltInGroup::Registered->value);
        }

        $this->start = new DateTimeImmutable('2026-11-01 12:00:00', new DateTimeZone('UTC'));
        $this->forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'F', 'f')->id;
        $this->thread = $this->threads->create($this->forum, $this->users['alice'], 'Topic', 'topic', $this->start);
        $this->first = $this->posts->add($this->thread, $this->users['alice'], 'First', 'bbcode', $this->start);
        $this->second = $this->posts->add($this->thread, $this->users['alice'], 'Second', 'bbcode', $this->start);
        $this->connection->update(
            'threads',
            ['first_post_id' => $this->first, 'last_post_id' => $this->second, 'reply_count' => 1],
            ['id' => $this->thread],
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testOneOpenReportPerUserAndPost(): void
    {
        $report = $this->report('bob', $this->first, 0, '  Spam   link  ');
        $row = $this->connection->fetchAssociative('SELECT * FROM reports WHERE id = ?', [$report]);

        self::assertIsArray($row);
        self::assertSame(['Spam link', 'open', $this->users['bob']], [
            $row['reason'],
            $row['status'],
            (int) $row['reporter_id'],
        ]);
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM outbox_events WHERE event_type = 'post.reported'",
        ));
        self::assertSame('report_exists', $this->violation('bob', $this->first, 60));

        $this->connection->update('reports', ['status' => 'resolved', 'open_reporter_id' => null], ['id' => $report]);

        self::assertGreaterThan($report, $this->report('bob', $this->first, 120, 'Again'), 'after it was closed');
    }

    public function testOneReportPerIntervalAndRefusalsLeaveNoHit(): void
    {
        $this->report('bob', $this->first, 0, 'One');

        $flood = $this->violationWithParameters('bob', $this->second, 10);

        self::assertSame(['report_flood_wait', ['seconds' => 20]], $flood);
        self::assertSame('report_exists', $this->violation('bob', $this->first, 31), 'refused at 31 s');
        self::assertGreaterThan(0, $this->report('bob', $this->second, 31, 'Two'), 'the refusal used no hit');
    }

    public function testUnreadableOwnAndForbiddenPostsAreRefused(): void
    {
        $staff = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'Staff', 'staff')->id;
        $hiddenThread = $this->threads->create($staff, $this->users['alice'], 'Secret', 'secret', $this->start);
        $hiddenPost = $this->posts->add($hiddenThread, $this->users['alice'], 'Secret', 'bbcode', $this->start);
        $this->rules->setGroupRule(BuiltInGroup::Registered->value, 'forum.view', false, $staff);
        $alice = $this->users['alice'];
        $pending = $this->posts->add($this->thread, $alice, 'Wait', 'bbcode', $this->start, PostStatus::Pending);

        self::assertSame('post_not_found', $this->violation('bob', $hiddenPost, 0), 'a guessed ID (IDOR)');
        self::assertSame('post_not_found', $this->violation('bob', $pending, 0));
        self::assertSame('post_not_found', $this->violation('bob', 999999, 0));
        self::assertSame('report_own_post', $this->violation('alice', $this->first, 0));
        self::assertSame('report_reason_invalid', $this->violation('bob', $this->first, 0, " \n "));
        self::assertSame('report_reason_invalid', $this->violation('bob', $this->first, 0, str_repeat('x', 501)));

        $this->rules->setGroupRule(BuiltInGroup::Registered->value, ReportPost::PERMISSION, false);
        self::assertSame('report_denied', $this->violation('bob', $this->first, 0));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reports'));
    }

    public function testHandlerAndAuthorPrivacy(): void
    {
        $bob = $this->session('bob');
        $post = ['post' => (string) $this->first];
        $form = $this->reportHandler($bob)->handle(Request::create('/posts/1/report'), $post);
        $sent = $this->reportHandler($bob)->handle(
            Request::create('/posts/1/report', 'POST', ['reason' => 'Rude reply by a stranger']),
            ['post' => (string) $this->first],
        );
        $guest = $this->reportHandler(new ArraySession())->handle(Request::create('/posts/1/report'), ['post' => '1']);
        $authorPage = (string) $this->threadPage($this->session('alice'))->getContent();
        $bobPage = (string) $this->threadPage($bob)->getContent();

        self::assertSame(Response::HTTP_OK, $form->getStatusCode());
        self::assertSame(Response::HTTP_SEE_OTHER, $sent->getStatusCode());
        self::assertSame('/posts/' . $this->first, $sent->headers->get('Location'));
        self::assertSame('/login', $guest->headers->get('Location'));
        self::assertStringNotContainsString('Rude reply', $authorPage);
        self::assertStringNotContainsString('bob', $authorPage, 'the reporter is never named to the author');
        self::assertStringNotContainsString('/report"', $authorPage, 'no report link on own posts');
        self::assertStringContainsString('/posts/' . $this->first . '/report"', $bobPage);
    }

    private function report(string $user, int $postId, int $seconds, string $reason): int
    {
        return $this->service($seconds)->report($this->as($user), $postId, $reason);
    }

    private function violation(string $user, int $postId, int $seconds, string $reason = 'Because'): string
    {
        return $this->violationWithParameters($user, $postId, $seconds, $reason)[0];
    }

    /**
     * @return array{string, array<string, int|string>}
     */
    private function violationWithParameters(string $user, int $postId, int $seconds, string $reason = 'Because'): array
    {
        try {
            $this->report($user, $postId, $seconds, $reason);
        } catch (ThreadRuleViolation $violation) {
            return [$violation->diagnostic, $violation->parameters];
        }

        self::fail('no violation');
    }

    private function service(int $seconds): ReportPost
    {
        $at = $this->start->modify('+' . $seconds . ' seconds');

        return new ReportPost(
            $this->posts,
            $this->threads,
            $this->visibility(),
            new DbalReportWriter($this->connection, new Outbox($this->connection)),
            static fn(): DateTimeImmutable => $at,
        );
    }

    private function as(string $user): SubjectPermissions
    {
        return $this->resolver()->user($this->users[$user], [BuiltInGroup::Registered->value]);
    }

    private function session(string $user): ArraySession
    {
        $session = new ArraySession();
        $session->set('_user_id', $this->users[$user]);

        return $session;
    }

    private function reportHandler(ArraySession $session): ReportHandler
    {
        return new ReportHandler(
            self::translator(),
            self::views(),
            $this->subjects($session),
            $this->service(0),
            new CsrfTokens($session),
            self::router(),
            new ErrorResponder(self::translator()),
        );
    }

    private function threadPage(ArraySession $session): Response
    {
        return (new ThreadPageHandler(
            self::translator(),
            self::views(),
            $this->subjects($session),
            $this->threads,
            $this->posts,
            new PostRenderer($this->posts),
            $this->visibility(),
            self::router(),
            new ErrorResponder(self::translator()),
        ))->handle(Request::create('/threads/' . $this->thread), ['thread' => (string) $this->thread, 'slug' =>
            (string) $this->connection->fetchOne('SELECT slug FROM threads WHERE id = ?', [$this->thread])]);
    }

    private function subjects(ArraySession $session): RequestSubject
    {
        return new RequestSubject(
            new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
            new DbalUsergroupRepository($this->connection),
            $this->resolver(),
        );
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

    private static function translator(): MessageTranslator
    {
        return TranslatorFactory::create(dirname(__DIR__, 2) . '/translations', 'en');
    }

    private static function views(): TemplateRenderer
    {
        return TwigFactory::create(
            dirname(__DIR__, 2),
            sys_get_temp_dir() . '/nolviko-no-storage',
            self::translator(),
        );
    }

    private static function router(): Router
    {
        return new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
    }
}
