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
use Nolviko\Modules\Moderation\Domain\ResolveReport;
use Nolviko\Modules\Moderation\Http\ModerationQueueHandler;
use Nolviko\Modules\Moderation\Http\ReportDecisionHandler;
use Nolviko\Modules\Moderation\Infrastructure\DbalModerationQueue;
use Nolviko\Modules\Moderation\Infrastructure\DbalReportDecisions;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\QueryCounter;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M04.07 on MySQL 8.4: open reports, pending posts and pending accounts are listed newest first with keyset
 * pages; moderators see only the forums where they hold the permission; resolving or dismissing needs a note,
 * happens once and is audited.
 */
final class ModerationQueueTest extends TestCase
{
    private Connection $connection;

    private QueryCounter $counter;

    private DbalPermissionRuleStore $rules;

    private TemporaryDirectory $storage;

    /** @var array<string, int> */
    private array $users = [];

    private int $open;

    private int $closed;

    /** @var list<int> */
    private array $reports = [];

    private int $closedReport;

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
        $this->rules = new DbalPermissionRuleStore($this->connection, PermissionCatalog::core());
        $this->storage = new TemporaryDirectory();
        $accounts = new DbalUserRepository($this->connection);
        $groups = new DbalUsergroupRepository($this->connection);
        $memberships = [
            'alice' => [BuiltInGroup::Registered],
            'bob' => [BuiltInGroup::Registered],
            'mod' => [BuiltInGroup::Moderators, BuiltInGroup::Registered],
            'admin' => [BuiltInGroup::Administrators, BuiltInGroup::Registered],
            'waiting' => [BuiltInGroup::Registered],
        ];

        foreach ($memberships as $name => $list) {
            $this->users[$name] = $accounts->create($name, $name . '@example.com', 'h', UserStatus::Active)->id;

            foreach ($list as $group) {
                $groups->addMember($this->users[$name], $group->value);
            }
        }

        $accounts->updateStatus($this->users['waiting'], UserStatus::PendingApproval);
        $forums = new DbalForumRepository($this->connection);
        $this->open = $forums->create(ForumType::Forum, 'Open', 'open')->id;
        $this->closed = $forums->create(ForumType::Forum, 'Elsewhere', 'elsewhere')->id;
        // Moderators handle nothing in the second forum.
        $this->rules->setGroupRule(BuiltInGroup::Moderators->value, ResolveReport::PERMISSION, false, $this->closed);
        $this->rules->setGroupRule(BuiltInGroup::Moderators->value, 'moderation.post.hide', false, $this->closed);

        foreach (range(1, 30) as $i) {
            $this->reports[] = $this->reportedPost($this->open, 'Bad ' . $i);
        }

        $this->closedReport = $this->reportedPost($this->closed, 'Elsewhere');
        $posts = new DbalPostRepository($this->connection);
        $firstThread = 'SELECT id FROM threads WHERE forum_id = ? LIMIT 1';
        $thread = (int) $this->connection->fetchOne($firstThread, [$this->open]);
        $other = (int) $this->connection->fetchOne($firstThread, [$this->closed]);

        foreach (range(1, 3) as $i) {
            $posts->add($thread, $this->users['alice'], 'Waiting ' . $i, 'bbcode', self::at($i), PostStatus::Pending);
        }

        $posts->add($other, $this->users['alice'], 'Waiting elsewhere', 'bbcode', self::at(9), PostStatus::Pending);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testModeratorsSeeTheirForumsInKeysetPages(): void
    {
        $first = (string) $this->queue('mod', 'reports')->getContent();
        $older = self::older($first);
        $query = (string) parse_url((string) $older, PHP_URL_QUERY);
        $second = (string) $this->queue('mod', 'reports', $query)->getContent();
        $ids = [...self::ids($first), ...self::ids($second)];

        self::assertSame(25, count(self::ids($first)));
        self::assertNotNull($older);
        self::assertSame(array_reverse($this->reports), $ids, 'every open report once, newest first');
        self::assertNull(self::older($second));
        self::assertStringNotContainsString('Elsewhere', $first . $second, 'not this moderator\'s forum');
        self::assertStringContainsString('Reported by bob: Bad 30', $first, 'moderators see the reporter');
        self::assertSame(3, substr_count((string) $this->queue('mod', 'posts')->getContent(), '<li id="queue-'));
        self::assertSame(Response::HTTP_NOT_FOUND, $this->queue('mod', 'users')->getStatusCode(), 'admins only');

        $users = (string) $this->queue('admin', 'users')->getContent();
        self::assertStringContainsString('waiting · waiting@example.com', $users);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->queue('bob', 'reports')->getStatusCode());
        self::assertSame('/login', $this->queue(null, 'reports')->headers->get('Location'));
        $bogus = $this->queue('mod', 'reports', 'tab=reports&before=x');

        self::assertSame(Response::HTTP_NOT_FOUND, $bogus->getStatusCode());
    }

    /**
     * A realistic shape: thousands of posts and threads, a few open reports. The queue walks the open reports
     * (`ix_reports_queue`) and probes their posts, instead of reading every post of the moderator's forums.
     */
    public function testTheReportListWalksItsIndex(): void
    {
        $values = [];

        foreach (range(1, 400) as $i) {
            $values[] = sprintf("(%d, 'Bulk %d', 'b', 'visible', '2026-11-01', '2026-11-01')", $this->open, $i);
        }

        $this->connection->executeStatement(
            'INSERT INTO threads (forum_id, title, slug, status, created_at, updated_at) VALUES '
                . implode(', ', $values),
        );
        $this->connection->executeStatement(
            "INSERT INTO posts (thread_id, user_id, content, content_format, status, created_at, updated_at) "
                . "SELECT t.id, NULL, 'Bulk', 'bbcode', 'visible', '2026-11-01', '2026-11-01' FROM threads t "
                . 'CROSS JOIN (SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 '
                . 'UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10) n',
        );
        TestDatabase::analyze($this->connection, 'threads, posts, reports');
        $scope = new ForumScope([$this->open]);
        (new DbalModerationQueue($this->connection))->openReports($scope, 26);
        $sql = (string) end($this->counter->statements);
        $plan = TestDatabase::explainRow($this->connection, $sql, [PHP_INT_MAX, $this->open], 'r');

        // Reports drive the join, read newest first by key (PRIMARY backwards or the status index), no sort.
        if (TestDatabase::driver() !== 'postgresql') {
            $rows = $this->connection->fetchAllAssociative('EXPLAIN ' . $sql, [PHP_INT_MAX, $this->open]);
            self::assertSame('r', $rows[0]['table'], json_encode($rows) ?: '');
        }

        if (TestDatabase::driver() === 'postgresql') {
            // A cost-based plan on a small table (scan plus top-N sort); it must still stop at the page size.
            self::assertStringContainsString('"Node Type": "Limit"', (string) $plan['plan']);
        } else {
            self::assertContains($plan['key'], ['PRIMARY', 'ix_reports_queue'], json_encode($plan) ?: '');
            self::assertStringNotContainsString('filesort', (string) $plan['Extra']);
        }
    }

    public function testResolveAndDismissNeedANoteHappenOnceAndAreAudited(): void
    {
        $report = $this->reports[0];

        self::assertSame('report_note_invalid', $this->violation('mod', $report, ResolveReport::RESOLVE, ' '));
        self::assertSame('moderation_action_invalid', $this->violation('mod', $report, 'delete', 'x'));
        self::assertSame('report_not_found', $this->violation('mod', $this->closedReport, ResolveReport::DISMISS, 'x'));
        self::assertSame('report_not_found', $this->violation('bob', $report, ResolveReport::RESOLVE, 'x'));

        $this->decisions()->decide($this->as('mod'), $report, ResolveReport::RESOLVE, 'Removed the link');

        $row = $this->connection->fetchAssociative('SELECT * FROM reports WHERE id = ?', [$report]);
        self::assertIsArray($row);
        self::assertSame(['resolved', null, $this->users['mod'], 'Removed the link'], [
            $row['status'],
            $row['open_reporter_id'],
            (int) $row['resolved_by'],
            $row['resolution_note'],
        ]);
        self::assertSame('report_not_open', $this->violation('mod', $report, ResolveReport::DISMISS, 'Again'));
        self::assertSame(
            [['report.resolve', 'Removed the link']],
            array_map(
                static fn(array $audit): array => [$audit['action'], $audit['reason']],
                $this->connection->fetchAllAssociative(
                    "SELECT action, reason FROM audit_logs WHERE target_type = 'report'",
                ),
            ),
        );
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM outbox_events WHERE event_type = 'report.resolved'",
        ));

        $handler = $this->decisionHandler('mod');
        $dismissed = $handler->handle(
            Request::create(
                '/moderation/reports/' . $this->reports[1],
                'POST',
                ['action' => 'dismiss', 'note' => 'Fine'],
            ),
            ['report' => (string) $this->reports[1]],
        );
        $again = $this->decisionHandler('mod')->handle(
            Request::create('/moderation/reports/' . $report, 'POST', ['action' => 'resolve', 'note' => 'x']),
            ['report' => (string) $report],
        );

        self::assertSame([Response::HTTP_SEE_OTHER, '/moderation/queue?tab=reports'], [
            $dismissed->getStatusCode(),
            $dismissed->headers->get('Location'),
        ]);
        self::assertSame(Response::HTTP_CONFLICT, $again->getStatusCode());
        self::assertSame(28, count($this->openReports()), 'two closed, 28 left in the moderator\'s forum');
    }

    private function reportedPost(int $forumId, string $reason): int
    {
        $threads = new DbalThreadRepository($this->connection);
        $posts = new DbalPostRepository($this->connection);
        $thread = (int) ($this->connection->fetchOne('SELECT id FROM threads WHERE forum_id = ? LIMIT 1', [$forumId])
            ?: $threads->create($forumId, $this->users['alice'], 'Topic ' . $forumId, 't', self::at(0)));
        $post = $posts->add($thread, $this->users['alice'], 'Text for ' . $reason, 'bbcode', self::at(1));
        $this->connection->insert('reports', [
            'post_id' => $post,
            'reporter_id' => $this->users['bob'],
            'open_reporter_id' => $this->users['bob'],
            'reason' => $reason,
            'status' => 'open',
            'created_at' => self::at(2)->format('Y-m-d H:i:s.u'),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @return list<int>
     */
    private function openReports(): array
    {
        return array_map(
            static fn($item): int => $item->id,
            (new DbalModerationQueue($this->connection))->openReports(new ForumScope([$this->open]), 100),
        );
    }

    private function violation(string $user, int $report, string $action, string $note): string
    {
        try {
            $this->decisions()->decide($this->as($user), $report, $action, $note);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('no violation');
    }

    private function queue(?string $user, string $tab, string $query = ''): Response
    {
        $session = $this->session($user);
        $request = Request::create('/moderation/queue?' . ($query === '' ? 'tab=' . $tab : $query));

        return (new ModerationQueueHandler(
            self::translator(),
            self::views(),
            $this->subjects($session),
            new DbalModerationQueue($this->connection),
            $this->visibility(),
            new CsrfTokens($session),
            self::router(),
            new ErrorResponder(self::translator()),
        ))->handle($request, []);
    }

    private function decisionHandler(string $user): ReportDecisionHandler
    {
        $session = $this->session($user);

        return new ReportDecisionHandler(
            self::translator(),
            self::views(),
            $this->subjects($session),
            $this->decisions(),
            self::router(),
            new ErrorResponder(self::translator()),
        );
    }

    private function decisions(): ResolveReport
    {
        return new ResolveReport(
            new DbalReportDecisions($this->connection, new Outbox($this->connection)),
            $this->visibility(),
        );
    }

    private function as(string $user): SubjectPermissions
    {
        $groups = array_map(
            'intval',
            (new DbalUsergroupRepository($this->connection))->groupIdsOf($this->users[$user]),
        );

        return $this->resolver()->user($this->users[$user], $groups);
    }

    private function session(?string $user): ArraySession
    {
        $session = new ArraySession();

        if ($user !== null) {
            $session->set('_user_id', $this->users[$user]);
        }

        return $session;
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

    /**
     * @return list<int>
     */
    private static function ids(string $html): array
    {
        preg_match_all('/<li id="queue-(\d+)">/', $html, $matches);

        return array_map('intval', $matches[1]);
    }

    private static function older(string $html): ?string
    {
        return preg_match('~<a rel="next" href="([^"]+)"~', $html, $match) === 1 ? html_entity_decode($match[1]) : null;
    }

    private static function at(int $minutes): DateTimeImmutable
    {
        return (new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('UTC')))
            ->modify('+' . $minutes . ' minutes');
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
