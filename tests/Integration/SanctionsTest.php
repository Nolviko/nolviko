<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
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
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Moderation\Domain\ManageSanctions;
use Nolviko\Modules\Moderation\Http\UserSanctionsHandler;
use Nolviko\Modules\Moderation\Infrastructure\DbalUserSanctions;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
use Nolviko\Modules\Users\Domain\Authenticator;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\LoginHandler;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Modules\Users\Infrastructure\DbalLoginAttempts;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M04.09 on MySQL 8.4: bans block login and every permission of a signed-in user, expire by time without a
 * scheduled job, can be lifted; warnings add points that expire; only `moderation.user.ban` holders sanction,
 * never themselves, and only administrators sanction administrators; each change is audited with an event.
 */
final class SanctionsTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private Connection $connection;

    private DbalUserSanctions $store;

    /** @var array<string, int> */
    private array $users = [];

    private DateTimeImmutable $now;

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
        $accounts = new DbalUserRepository($this->connection);
        $groups = new DbalUsergroupRepository($this->connection);
        $hash = (new PasswordHasher())->hash(self::PASSWORD);
        $memberships = [
            'alice' => [BuiltInGroup::Registered],
            'mod' => [BuiltInGroup::Moderators, BuiltInGroup::Registered],
            'admin' => [BuiltInGroup::Administrators, BuiltInGroup::Registered],
        ];

        foreach ($memberships as $name => $list) {
            $this->users[$name] = $accounts->create($name, $name . '@example.com', $hash, UserStatus::Active)->id;

            foreach ($list as $group) {
                $groups->addMember($this->users[$name], $group->value);
            }
        }

        $this->store = new DbalUserSanctions($this->connection, new Outbox($this->connection));
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testBanBlocksLoginAndPermissionsUntilLifted(): void
    {
        self::assertTrue($this->subjectOf('alice')->allows('forum.thread.create'));

        $this->service()->apply($this->as('mod'), $this->users['alice'], [
            'action' => ManageSanctions::BAN,
            'reason' => '  Spam   links ',
        ]);

        $login = $this->login('alice');
        self::assertSame(Response::HTTP_FORBIDDEN, $login->getStatusCode());
        self::assertStringContainsString('This account is banned.', (string) $login->getContent());

        $wrongPassword = $this->login('alice', 'wrong password');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $wrongPassword->getStatusCode(), 'ban stays hidden');

        $banned = $this->subjectOf('alice');
        self::assertFalse($banned->allows('forum.thread.create'), 'a session of a banned user acts as a guest');
        self::assertFalse($banned->allows('forum.post.reply'));

        self::assertSame(
            'Spam links',
            $this->connection->fetchOne('SELECT reason FROM user_bans WHERE user_id = ?', [$this->users['alice']]),
        );

        $this->service()->apply($this->as('mod'), $this->users['alice'], [
            'action' => ManageSanctions::LIFT,
            'reason' => 'Appeal accepted',
        ]);

        self::assertSame(Response::HTTP_SEE_OTHER, $this->login('alice')->getStatusCode());
        self::assertTrue($this->subjectOf('alice')->allows('forum.thread.create'));
        self::assertSame('user_not_banned', $this->violation('mod', 'alice', [
            'action' => ManageSanctions::LIFT,
            'reason' => 'Again',
        ]));
        self::assertSame(
            ['user.ban', 'user.unban'],
            $this->connection->fetchFirstColumn("SELECT action FROM audit_logs WHERE target_type = 'user' ORDER BY id"),
        );
        self::assertSame(
            ['user.banned', 'user.unbanned'],
            $this->connection->fetchFirstColumn(
                "SELECT event_type FROM outbox_events WHERE event_type LIKE 'user.%' ORDER BY id",
            ),
        );
    }

    public function testTemporaryBansAndWarningsExpireWithoutAJob(): void
    {
        $this->service()->apply($this->as('mod'), $this->users['alice'], [
            'action' => ManageSanctions::BAN,
            'reason' => 'Cool down',
            'days' => '2',
        ]);
        $alice = $this->users['alice'];
        $until = $this->store->activeBanUntil($alice, $this->now);

        self::assertInstanceOf(DateTimeImmutable::class, $until);
        self::assertSame($this->now->modify('+2 days')->format('Y-m-d H:i'), $until->format('Y-m-d H:i'));
        self::assertStringContainsString('banned until', (string) $this->login('alice')->getContent());
        self::assertFalse($this->store->activeBanUntil($alice, $this->now->modify('+3 days')));

        $this->service()->apply($this->as('mod'), $alice, [
            'action' => ManageSanctions::WARN,
            'reason' => 'Rude',
            'points' => '3',
            'days' => '7',
        ]);
        $this->service()->apply($this->as('admin'), $alice, [
            'action' => ManageSanctions::WARN,
            'reason' => 'Rude again',
            'points' => '5',
        ]);

        self::assertSame(8, $this->store->activePoints($alice, $this->now));
        self::assertSame(5, $this->store->activePoints($alice, $this->now->modify('+8 days')));

        $this->connection->executeStatement('UPDATE user_bans SET expires_at = ? WHERE user_id = ?', [
            $this->now->modify('-1 minute')->format('Y-m-d H:i:s.u'),
            $alice,
        ]);

        self::assertSame(Response::HTTP_SEE_OTHER, $this->login('alice')->getStatusCode(), 'an expired ban lets in');
    }

    public function testOnlyEntitledModeratorsSanctionAndInputIsChecked(): void
    {
        $warn = ['action' => ManageSanctions::WARN, 'reason' => 'x', 'points' => '1'];

        self::assertSame('moderation_denied', $this->violation('alice', 'mod', $warn));
        self::assertSame('sanction_self', $this->violation('mod', 'mod', $warn));
        self::assertSame('sanction_admin_denied', $this->violation('mod', 'admin', $warn));
        self::assertSame('moderation_action_invalid', $this->violation('mod', 'alice', ['action' => 'delete']));
        self::assertSame('sanction_reason_invalid', $this->violation('mod', 'alice', ['reason' => ' '] + $warn));
        self::assertSame('warning_points_invalid', $this->violation('mod', 'alice', ['points' => '101'] + $warn));
        self::assertSame('warning_points_invalid', $this->violation('mod', 'alice', ['points' => '0'] + $warn));
        self::assertSame('sanction_days_invalid', $this->violation('mod', 'alice', ['days' => '3651'] + $warn));
        self::assertSame('sanction_days_invalid', $this->violation('mod', 'alice', ['days' => '-1'] + $warn));

        $this->service()->apply($this->as('admin'), $this->users['mod'], $warn);
        self::assertSame(1, $this->store->activePoints($this->users['mod'], $this->now), 'admins sanction moderators');

        $missing = null;

        try {
            $this->service()->standing($this->as('mod'), 999_999);
        } catch (ThreadRuleViolation $violation) {
            $missing = $violation->diagnostic;
        }

        self::assertSame('user_not_found', $missing);
    }

    public function testHandlerShowsStandingAndAppliesActions(): void
    {
        $session = $this->session('mod');
        $handler = $this->handler($session);
        $alice = ['user' => (string) $this->users['alice']];
        $page = $handler->handle(Request::create('/moderation/users/' . $this->users['alice']), $alice);

        self::assertSame(Response::HTTP_OK, $page->getStatusCode());
        self::assertStringContainsString('This user is not banned.', (string) $page->getContent());
        self::assertStringContainsString('Active warning points: 0', (string) $page->getContent());

        $ban = $handler->handle(Request::create('/moderation/users/1', 'POST', [
            'action' => ManageSanctions::BAN,
            'reason' => 'Spam',
        ]), $alice);

        self::assertSame(Response::HTTP_SEE_OTHER, $ban->getStatusCode());
        self::assertStringContainsString(
            'banned until the ban is lifted',
            (string) $this->handler($session)->handle(Request::create('/'), $alice)->getContent(),
        );

        $refused = $this->handler($session)->handle(Request::create('/', 'POST', [
            'action' => ManageSanctions::BAN,
            'reason' => '',
        ]), $alice);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $refused->getStatusCode());

        $member = $this->handler($this->session('alice'))->handle(Request::create('/'), ['user' => '1']);
        self::assertSame(Response::HTTP_FORBIDDEN, $member->getStatusCode());
        $unknown = $this->handler($session)->handle(Request::create('/'), ['user' => '999999']);
        self::assertSame(Response::HTTP_NOT_FOUND, $unknown->getStatusCode());
        $guest = $this->handler(new ArraySession())->handle(Request::create('/'), $alice);
        self::assertSame('/login', $guest->headers->get('Location'));
    }

    /**
     * @param array<string, string> $input
     */
    private function violation(string $actor, string $target, array $input): string
    {
        try {
            $this->service()->apply($this->as($actor), $this->users[$target], $input);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('expected a violation');
    }

    private function service(): ManageSanctions
    {
        $users = new DbalUserRepository($this->connection);
        $groups = new DbalUsergroupRepository($this->connection);
        $resolver = $this->resolver();
        $targets = static function (int $userId) use ($users, $groups, $resolver): ?array {
            $user = $users->findById($userId);

            return $user === null ? null : [
                'username' => $user->username,
                'admin' => $resolver->user($userId, $groups->groupIdsOf($userId))->allows(ManageSanctions::ADMIN),
            ];
        };

        return new ManageSanctions($this->store, $targets, fn(): DateTimeImmutable => $this->now);
    }

    private function as(string $user): SubjectPermissions
    {
        $groups = new DbalUsergroupRepository($this->connection);

        return $this->resolver()->user($this->users[$user], $groups->groupIdsOf($this->users[$user]));
    }

    private function subjectOf(string $user): SubjectPermissions
    {
        return $this->subjects($this->session($user))->of(Request::create('/'));
    }

    private function session(string $user): ArraySession
    {
        $session = new ArraySession();
        $session->set('_user_id', $this->users[$user]);

        return $session;
    }

    private function subjects(ArraySession $session): RequestSubject
    {
        return new RequestSubject(
            new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
            new DbalUsergroupRepository($this->connection),
            $this->resolver(),
            $this->store,
        );
    }

    private function resolver(): PermissionResolver
    {
        $catalog = PermissionCatalog::core();

        return new PermissionResolver(
            $catalog,
            new DbalPermissionRuleStore($this->connection, $catalog),
            new DbalForumTree($this->connection),
        );
    }

    private function login(string $user, string $password = self::PASSWORD): Response
    {
        $session = new ArraySession();
        $handler = new LoginHandler(
            self::translator(),
            self::views(),
            new Authenticator(new DbalUserRepository($this->connection), new PasswordHasher()),
            new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
            new CsrfTokens($session),
            self::router(),
            new LoginThrottle(new DbalLoginAttempts($this->connection), random: static fn(): int => 2),
            $this->store,
        );

        return $handler->handle(Request::create('/login', 'POST', [
            'identifier' => $user,
            'password' => $password,
        ]), []);
    }

    private function handler(ArraySession $session): UserSanctionsHandler
    {
        return new UserSanctionsHandler(
            self::translator(),
            self::views(),
            $this->subjects($session),
            $this->service(),
            new CsrfTokens($session),
            self::router(),
            new ErrorResponder(self::translator()),
        );
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
