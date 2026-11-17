<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Application\TaskConsent;
use Nolviko\Core\Application\TaskRuntime;
use Nolviko\Core\Cache\FileCache;
use Nolviko\Core\Cache\RedisSettingsStore;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\OutboxQueue;
use Nolviko\Core\Events\Outbox;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\RouterFactory;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Community\Infrastructure\CommunityAccess;
use Nolviko\Modules\Community\Infrastructure\CommunityInteractions;
use Nolviko\Modules\Community\Infrastructure\CommunityNotifications;
use Nolviko\Modules\Community\Infrastructure\CommunityPolls;
use Nolviko\Modules\Community\Infrastructure\ImageProcessor;
use Nolviko\Modules\Community\Infrastructure\MemberProfiles;
use Nolviko\Modules\Community\Infrastructure\PrivateMedia;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Search\Domain\ExternalSearchAdapter;
use Nolviko\Modules\Search\Domain\SearchFilters;
use Nolviko\Modules\Search\Domain\SearchQuery;
use Nolviko\Modules\Search\Infrastructure\AuthorizedExternalSearch;
use Nolviko\Modules\Search\Infrastructure\DbalPostSearch;
use Nolviko\Modules\Tasks\Http\TaskAdminHandler;
use Nolviko\Modules\Tasks\Infrastructure\CounterAggregation;
use Nolviko\Modules\Tasks\Infrastructure\TaskAdministration;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\QueryCounter;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/** Real-engine M08 queue administration, scoped search and exact counter contract. */
final class TaskManagementTest extends TestCase
{
    private Connection $db;

    private QueryCounter $counter;

    private TemporaryDirectory $files;

    private CommunityAccess $access;

    private MemberProfiles $profiles;

    private CommunityInteractions $actions;

    private CommunityPolls $polls;

    private CommunityNotifications $notifications;

    private PrivateMedia $media;

    private PermissionResolver $resolver;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->counter = new QueryCounter();
        $this->db = ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32))
        ), [$this->counter]);

        if (!TestDatabase::isDisposable($this->db)) {
            self::fail('refusing installed forum');
        }

        TestDatabase::reset($this->db);
        $this->files = new TemporaryDirectory();
        $this->files->write('storage/.keep', '');
        $this->files->write('public/.keep', '');
        $now = CommunityInteractions::now();

        foreach ([1 => 'Alice', 2 => 'Bob', 3 => 'Carol'] as $id => $name) {
            $this->db->insert('users', ['id' => $id, 'username' => $name, 'username_normalized' => strtolower($name),
                'email' => $name . '@example.test', 'email_normalized' => strtolower($name) . '@example.test',
                'password_hash' => 'unused', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->db->insert('forums', ['id' => 1, 'type' => 'forum', 'title' => 'Public', 'slug' => 'public',
            'created_at' => $now, 'updated_at' => $now]);
        $this->db->insert('threads', ['id' => 1, 'forum_id' => 1, 'user_id' => 1, 'title' => 'Discussion',
            'slug' => 'discussion', 'status' => 'visible', 'created_at' => $now, 'updated_at' => $now]);

        foreach ([1 => 1, 2 => 2] as $post => $author) {
            $this->db->insert('posts', ['id' => $post, 'thread_id' => 1, 'user_id' => $author,
                'content' => 'Post', 'content_format' => 'bbcode', 'status' => 'visible',
                'created_at' => $now, 'updated_at' => $now]);
        }

        $this->db->update('threads', ['first_post_id' => 1, 'last_post_id' => 2], ['id' => 1]);
        $catalog = PermissionCatalog::core(true);
        $this->resolver = new PermissionResolver(
            $catalog,
            new DbalPermissionRuleStore($this->db, $catalog),
            new DbalForumTree($this->db)
        );
        $visibility = new ForumVisibility(new FileCache(null), static fn(): int => 1);
        $this->access = new CommunityAccess($this->db, $visibility);
        $this->profiles = new MemberProfiles($this->db, $this->access);
        $this->actions = new CommunityInteractions($this->db, $this->access, $this->profiles);
        $this->polls = new CommunityPolls($this->db, $this->access);
        $this->notifications = new CommunityNotifications($this->db, $this->access, $this->actions);
        $this->media = new PrivateMedia(
            $this->db,
            $this->access,
            $this->profiles,
            new ImageProcessor(),
            $this->files->path . '/storage',
            $this->files->path . '/public'
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->files)) {
            $this->files->remove();
        }

        if (isset($this->db)) {
            TestDatabase::release($this->db);
        }
    }

    public function testFailedTaskDiagnosticsAreRedactedAndOnlyFailedTasksCanRetry(): void
    {
        $this->db->transactional(fn(): bool => (new Outbox($this->db))->record(
            'fixture.private',
            'fixture',
            1,
            ['secret' => 'runtime-private-payload']
        ));
        $id = (int) $this->db->fetchOne('SELECT id FROM outbox_events');
        $this->db->executeStatement("UPDATE outbox_events SET status = 'failed', attempts = 8");
        $admin = new TaskAdministration($this->db);
        $this->counter->count = 0;
        $rows = $admin->page('failed', 0);
        self::assertSame(1, $this->counter->count);
        self::assertCount(1, $rows);
        self::assertStringNotContainsString('runtime-private-payload', json_encode($rows, JSON_THROW_ON_ERROR));
        $this->counter->count = 0;
        self::assertSame(1, (int) $admin->summary()['failed']);
        self::assertSame(3, $this->counter->count);
        self::assertTrue($admin->retry($id));
        self::assertFalse($admin->retry($id));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT attempts FROM outbox_events WHERE id = ?', [$id]));
    }

    public function testQueueLeasesUseIndependentCommittedClaimsAndFenceStaleWorkers(): void
    {
        $this->db->transactional(fn(): bool => (new Outbox($this->db))->record('fixture.event', 'fixture', 1, []));
        $queue = new OutboxQueue($this->db);
        $first = $queue->claim(['fixture.event']);
        self::assertNotNull($first);
        self::assertNull($queue->claim(['fixture.event']));
        $this->db->executeStatement(
            'UPDATE outbox_events SET lease_expires_at = CURRENT_TIMESTAMP(6) - INTERVAL \'1\' SECOND'
        );
        $second = $queue->claim(['fixture.event']);
        self::assertNotNull($second);

        try {
            $this->db->transactional(fn() => $queue->complete($first));
            self::fail('stale completion accepted');
        } catch (RuntimeException) {
            self::assertNotSame($first->token, $second->token);
        }

        $queue->retry($second);
        self::assertSame('pending', $this->db->fetchOne('SELECT status FROM outbox_events'));
    }

    public function testRegisteredRuntimeProcessesCoreWorkAndPreservesUnknownWork(): void
    {
        $this->db->transactional(function (): void {
            $outbox = new Outbox($this->db);
            $outbox->record('fixture.unknown', 'fixture', 1, []);
            $outbox->record('post.created', 'post', 2, ['post_id' => 2, 'thread_id' => 1,
                'user_id' => 2, 'status' => 'visible']);
        });
        $result = $this->runtime()->run(1, 5000);
        self::assertSame(1, $result['completed']);
        self::assertSame('pending', $this->db->fetchOne(
            "SELECT status FROM outbox_events WHERE event_type = 'fixture.unknown'"
        ));
        self::assertSame('completed', $this->db->fetchOne(
            "SELECT status FROM outbox_events WHERE event_type = 'post.created'"
        ));
    }

    public function testExactCountersRemainScopedAndOutboxReplayDoesNotIncrementAgain(): void
    {
        $this->db->insert('forum_statistics', ['forum_id' => 1, 'thread_count' => 1, 'post_count' => 2,
            'updated_at' => CommunityInteractions::now()]);
        $counters = new CounterAggregation($this->db);
        self::assertSame(['threads' => 1, 'posts' => 2], $counters->visible(new ForumScope([1])));
        self::assertSame(['threads' => 0, 'posts' => 0], $counters->visible(new ForumScope([])));
        $this->db->transactional(fn(): bool => (new Outbox($this->db))->record(
            'post.created',
            'post',
            2,
            ['post_id' => 2, 'thread_id' => 1, 'user_id' => 2, 'status' => 'visible']
        ));
        $this->runtime()->run(1, 5000);
        self::assertSame(['threads' => 1, 'posts' => 2], $counters->visible(new ForumScope([1])));
    }

    public function testNativeSearchFiltersAndExternalCandidatesCannotExposePrivateRows(): void
    {
        $this->db->executeStatement("UPDATE posts SET content = 'm08acornquery <script>alert(1)</script>'");
        $search = new DbalPostSearch($this->db);
        $query = SearchQuery::parse('m08acornquery');
        $scope = new ForumScope([1]);
        $this->counter->count = 0;
        self::assertSame(2, $search->find($scope, $query)['count']);
        self::assertSame(2, $this->counter->count);
        self::assertSame(1, $search->find($scope, $query, filters: new SearchFilters(author: 1))['count']);
        self::assertSame(0, $search->find($scope, $query, filters: new SearchFilters(forum: 2))['count']);
        self::assertSame(0, $search->find($scope, $query, filters: new SearchFilters(from: '2099-01-01'))['count']);
        $this->db->update('posts', ['status' => 'hidden'], ['id' => 2]);
        $adapter = new class implements ExternalSearchAdapter {
            public function candidates(SearchQuery $query, SearchFilters $filters, ?int $before): array
            {
                return [2, 1, 99999];
            }
        };
        $external = new AuthorizedExternalSearch($this->db, $adapter);
        $result = $external->find($scope, $query);
        self::assertCount(1, $result['rows']);
        self::assertTrue($result['partial']);
        self::assertFalse($result['capped']);
        self::assertSame(1, (int) $result['rows'][0]['id']);
        self::assertIsString($result['rows'][0]['excerpt']);
        self::assertCount(0, $external->find(new ForumScope([]), $query)['rows']);
    }

    public function testAdminCommandsRequireFreshAuthenticationAndCsrf(): void
    {
        $this->db->insert('usergroup_members', ['user_id' => 1, 'usergroup_id' => 4,
            'created_at' => CommunityInteractions::now()]);
        $session = new ArraySession();
        $session->set('_user_id', 1);
        $csrf = new CsrfTokens($session);
        $admin = new AdminSession($session);
        $handler = $this->handler($session, $admin, $csrf);
        $parameters = ['_route' => CoreRoutes::ADMIN_TASKS];
        $request = Request::create('https://example.test/admin/tasks', 'POST', ['action' => 'enable_cli',
            CsrfTokens::FIELD => $csrf->token()]);
        $admin->confirm();
        $bad = Request::create('https://example.test/admin/tasks', 'POST', ['action' => 'enable_cli',
            CsrfTokens::FIELD => 'wrong']);
        self::assertSame(403, $handler->handle($bad, $parameters)->getStatusCode());
        self::assertSame(200, $handler->handle($request, $parameters)->getStatusCode());
        self::assertTrue((new TaskConsent($this->files->path . '/storage'))->enabled());
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) FROM audit_logs'));
    }

    public function testNonAdministratorCannotUseTaskCommands(): void
    {
        $this->db->insert('usergroup_members', ['user_id' => 1, 'usergroup_id' => 2,
            'created_at' => CommunityInteractions::now()]);
        $session = new ArraySession();
        $session->set('_user_id', 1);
        $admin = new AdminSession($session);
        $admin->confirm();
        $csrf = new CsrfTokens($session);
        $request = Request::create('https://example.test/admin/tasks', 'POST', ['action' => 'run',
            CsrfTokens::FIELD => $csrf->token()]);
        self::assertSame(403, $this->handler($session, $admin, $csrf)
            ->handle($request, ['_route' => CoreRoutes::ADMIN_TASKS])->getStatusCode());
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM audit_logs'));
    }

    private function runtime(): TaskRuntime
    {
        $config = new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32))
        );

        return new TaskRuntime($this->db, $config, dirname(__DIR__, 2), $this->files->path . '/storage');
    }

    private function handler(ArraySession $session, AdminSession $admin, CsrfTokens $csrf): TaskAdminHandler
    {
        $root = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($root . '/translations', 'en');
        $urls = RouterFactory::create($root, '/missing-task-route-cache');
        $subjects = new RequestSubject(
            new AuthSession($session, $csrf, new MemoryUsers(), new ResponseCookies()),
            new DbalUsergroupRepository($this->db),
            $this->resolver
        );

        return new TaskAdminHandler(
            new TaskAdministration($this->db),
            $this->runtime(),
            new TaskConsent($this->files->path . '/storage'),
            new RedisSettingsStore($this->files->path . '/storage'),
            $subjects,
            $admin,
            $csrf,
            new DbalAdminAudit($this->db),
            TwigFactory::create($root, '/missing-task-view-cache', $translator, urls: $urls),
            $translator,
            $urls
        );
    }
}
