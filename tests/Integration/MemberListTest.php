<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Http\MembersHandler;
use Nolviko\Modules\Admin\Infrastructure\DbalMemberList;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M04.06: the member list pages newest first with name and status filters; bulk suspend and reactivate need a
 * fresh confirmation, skip administrators and the actor, are audited and end a suspended member's sessions.
 */
final class MemberListTest extends TestCase
{
    private Connection $connection;

    private int $admin;

    /** @var list<int> */
    private array $members = [];

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
        $users = new DbalUserRepository($this->connection);
        $groups = new DbalUsergroupRepository($this->connection);
        $this->admin = $users->create('Admin', 'admin@example.com', 'h', UserStatus::Active)->id;
        $groups->addMember($this->admin, BuiltInGroup::Administrators->value);

        foreach (range(1, 60) as $i) {
            $status = $i % 10 === 0 ? UserStatus::PendingApproval : UserStatus::Active;
            $this->members[] = $users->create(sprintf('member%02d', $i), 'm' . $i . '@example.com', 'h', $status)->id;
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testPagesAndFilters(): void
    {
        $list = new DbalMemberList($this->connection);
        $first = $list->page('', null, null);
        $second = $list->page('', null, $first[DbalMemberList::PAGE_SIZE - 1]['id']);

        self::assertCount(DbalMemberList::PAGE_SIZE + 1, $first);
        self::assertSame('member60', $first[0]['username']);
        self::assertCount(11, $second, '61 accounts in all');
        self::assertCount(9, $list->page('MEMBER0', null, null), 'member01-09, prefix, case-folded');
        self::assertCount(6, $list->page('', UserStatus::PendingApproval, null));
        self::assertSame([], $list->page('%', null, null), 'LIKE wildcards are literal');
    }

    public function testBulkSuspendSkipsAdminsAndNeedsConfirmation(): void
    {
        $session = new ArraySession();
        $session->set('_user_id', $this->admin);
        $form = ['action' => MembersHandler::SUSPEND, 'members' => [
            (string) $this->members[0],
            (string) $this->members[1],
            (string) $this->admin,
            'x',
        ]];

        $stale = $this->handler($session, true)->handle(Request::create('/admin/members/bulk', 'POST', $form), []);
        self::assertSame(Response::HTTP_FORBIDDEN, $stale->getStatusCode());

        (new AdminSession($session))->confirm();
        $done = $this->handler($session, true)->handle(Request::create('/admin/members/bulk', 'POST', $form), []);
        self::assertSame(Response::HTTP_SEE_OTHER, $done->getStatusCode());
        self::assertSame('/admin/members?changed=2', $done->headers->get('Location'));
        self::assertSame('suspended', $this->accountStatus($this->members[0]));
        self::assertSame('active', $this->accountStatus($this->admin), 'administrators are skipped');
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT session_epoch FROM users WHERE id = ?',
            [$this->members[0]],
        ));
        self::assertSame(2, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'user.suspend'",
        ));

        $back = $this->handler($session, true)->handle(Request::create('/admin/members/bulk', 'POST', [
            'action' => MembersHandler::REACTIVATE,
            'members' => [(string) $this->members[0]],
        ]), []);
        self::assertSame('/admin/members?changed=1', $back->headers->get('Location'));
        self::assertSame('active', $this->accountStatus($this->members[0]));

        $page = (string) $this->handler($session, false)
            ->handle(Request::create('/admin/members?status=suspended'), [])->getContent();
        self::assertStringContainsString('member02', $page);
        self::assertStringNotContainsString('member03', $page);
    }

    private function accountStatus(int $userId): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM users WHERE id = ?', [$userId]);
    }

    private function handler(ArraySession $session, bool $bulk): MembersHandler
    {
        $translator = TranslatorFactory::create(dirname(__DIR__, 2) . '/translations', 'en');
        $catalog = PermissionCatalog::core();

        return new MembersHandler(
            $translator,
            TwigFactory::create(dirname(__DIR__, 2), sys_get_temp_dir() . '/nolviko-no-storage', $translator),
            new RequestSubject(
                new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
                new DbalUsergroupRepository($this->connection),
                new PermissionResolver(
                    $catalog,
                    new DbalPermissionRuleStore($this->connection, $catalog),
                    new DbalForumTree($this->connection),
                ),
            ),
            new DbalMemberList($this->connection),
            new AdminSession($session),
            new CsrfTokens($session),
            new Router(CompiledRoutes::compile(CoreRoutes::definitions())),
            $bulk,
        );
    }
}
