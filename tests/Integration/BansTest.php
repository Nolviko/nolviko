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
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Http\BanListHandler;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Moderation\Domain\BanEntry;
use Nolviko\Modules\Moderation\Domain\ManageBans;
use Nolviko\Modules\Moderation\Infrastructure\DbalBanList;
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
 * M04.09a on MySQL 8.4: IP, IPv4/IPv6 range, email and email-domain bans are enforced at login and on every
 * request of a signed-in user (which covers posting) and are ready for registration; they expire without a
 * job, can be lifted, are audited, and only administrators with a fresh password confirmation change them.
 */
final class BansTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private Connection $connection;

    private DbalBanList $bans;

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
            'alice' => ['alice@example.com', BuiltInGroup::Registered],
            'bob' => ['bob@spam.example', BuiltInGroup::Registered],
            'mod' => ['mod@example.com', BuiltInGroup::Moderators],
            'admin' => ['admin@example.com', BuiltInGroup::Administrators],
        ];

        foreach ($memberships as $name => [$email, $group]) {
            $this->users[$name] = $accounts->create($name, $email, $hash, UserStatus::Active)->id;
            $groups->addMember($this->users[$name], BuiltInGroup::Registered->value);

            if ($group !== BuiltInGroup::Registered) {
                $groups->addMember($this->users[$name], $group->value);
            }
        }

        $this->bans = new DbalBanList($this->connection, new Outbox($this->connection));
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testIpBanBlocksLoginAndSignedInRequestsFromThatAddressOnly(): void
    {
        $this->add(BanEntry::IP, '192.0.2.7');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->login('alice', '192.0.2.7')->getStatusCode());
        self::assertSame(Response::HTTP_SEE_OTHER, $this->login('alice', '192.0.2.8')->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->login('alice', '::ffff:192.0.2.7')->getStatusCode());
        self::assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->login('alice', '192.0.2.7', 'wrong')->getStatusCode(),
            'the ban is not disclosed without the password',
        );
        self::assertFalse($this->subjectOf('alice', '192.0.2.7')->allows('forum.thread.create'), 'cannot post');
        self::assertTrue($this->subjectOf('alice', '192.0.2.8')->allows('forum.thread.create'));
    }

    public function testRangesMatchTheirOwnAddressFamilyOnly(): void
    {
        $this->add(BanEntry::IP_RANGE, '2001:db8::/32');
        $this->add(BanEntry::IP_RANGE, '198.51.100.0/22');

        self::assertTrue($this->bans->blocks('2001:db8:ffff::1', null, $this->now));
        self::assertTrue($this->bans->blocks('2001:db8::', null, $this->now));
        self::assertFalse($this->bans->blocks('2001:db9::1', null, $this->now));
        self::assertFalse($this->bans->blocks('2001:db7:ffff:ffff:ffff:ffff:ffff:ffff', null, $this->now));
        self::assertTrue($this->bans->blocks('198.51.103.255', null, $this->now));
        self::assertFalse($this->bans->blocks('198.51.104.0', null, $this->now));
        self::assertFalse($this->bans->blocks('32.1.13.184', null, $this->now), 'IPv4 bytes of 2001:db8 do not match');
        self::assertFalse($this->bans->blocks('not an address', null, $this->now));
        self::assertSame(Response::HTTP_FORBIDDEN, $this->login('alice', '2001:db8:1::5')->getStatusCode());
    }

    public function testEmailAndDomainBansCoverRegistrationLoginAndSessions(): void
    {
        $this->add(BanEntry::EMAIL_DOMAIN, 'spam.example');
        $this->add(BanEntry::EMAIL, 'Mallory@Example.com');

        self::assertTrue($this->bans->blocks(null, 'mallory@example.COM', $this->now), 'registration check');
        self::assertTrue($this->bans->blocks(null, 'anyone@spam.example', $this->now));
        self::assertFalse($this->bans->blocks(null, 'anyone@sub.spam.example', $this->now), 'exact domain only');
        self::assertFalse($this->bans->blocks(null, 'alice@example.com', $this->now));
        self::assertSame(Response::HTTP_FORBIDDEN, $this->login('bob', '192.0.2.1')->getStatusCode());
        self::assertStringContainsString(
            'Logins from this address or for this email address are not allowed.',
            (string) $this->login('bob', '192.0.2.1')->getContent(),
        );
        self::assertFalse($this->subjectOf('bob', '192.0.2.1')->allows('forum.post.reply'));
        self::assertTrue($this->subjectOf('alice', '192.0.2.1')->allows('forum.post.reply'));
    }

    public function testBansExpireAndAreLiftedWithAudit(): void
    {
        $service = $this->service();
        $service->apply($this->as('admin'), [
            'action' => ManageBans::ADD,
            'type' => BanEntry::IP,
            'value' => '192.0.2.50',
            'reason' => 'Flooding',
            'days' => '1',
        ], '203.0.113.1');

        self::assertTrue($this->bans->blocks('192.0.2.50', null, $this->now));
        self::assertFalse($this->bans->blocks('192.0.2.50', null, $this->now->modify('+2 days')), 'expired');

        $banId = $this->bans->active($this->now, 10)[0]['id'];
        $service->apply($this->as('admin'), ['action' => ManageBans::LIFT, 'ban' => (string) $banId], null);

        self::assertFalse($this->bans->blocks('192.0.2.50', null, $this->now));
        self::assertSame([], $this->bans->active($this->now, 10));
        self::assertSame('ban_not_found', $this->violation('admin', [
            'action' => ManageBans::LIFT,
            'ban' => (string) $banId,
        ]));
        self::assertSame(
            ['ban.add', 'ban.lift'],
            $this->connection->fetchFirstColumn("SELECT action FROM audit_logs WHERE target_type = 'ban' ORDER BY id"),
        );
        self::assertSame(
            ['ban.added', 'ban.lifted'],
            $this->connection->fetchFirstColumn(
                "SELECT event_type FROM outbox_events WHERE event_type LIKE 'ban.%' ORDER BY id",
            ),
        );
    }

    public function testOnlyAdministratorsChangeBansAndNotTheirOwnAddress(): void
    {
        $add = ['action' => ManageBans::ADD, 'type' => BanEntry::IP, 'value' => '192.0.2.9', 'reason' => 'x'];

        self::assertSame('moderation_denied', $this->violation('mod', $add));
        self::assertSame('moderation_denied', $this->violation('alice', $add));
        self::assertSame('ban_own_address', $this->violation('admin', $add, '192.0.2.9'));
        self::assertSame('ban_own_address', $this->violation('admin', [
            'type' => BanEntry::IP_RANGE,
            'value' => '192.0.2.0/24',
        ] + $add, '192.0.2.9'));
        self::assertSame('ban_value_invalid', $this->violation('admin', ['value' => '999.0.0.1'] + $add));
        self::assertSame('ban_type_invalid', $this->violation('admin', ['type' => 'user'] + $add));
        self::assertSame('sanction_reason_invalid', $this->violation('admin', ['reason' => ''] + $add));
        self::assertSame('moderation_action_invalid', $this->violation('admin', ['action' => 'purge'] + $add));
        self::assertSame('ban_not_found', $this->violation('admin', ['action' => ManageBans::LIFT, 'ban' => 'x']));
    }

    public function testAdminPageNeedsAFreshConfirmation(): void
    {
        $session = $this->session('admin');
        $add = [
            'action' => ManageBans::ADD,
            'type' => BanEntry::EMAIL_DOMAIN,
            'value' => 'spam.example',
            'reason' => 'Spam wave',
        ];
        (new AdminSession($session))->confirm();
        $added = $this->handler($session)->handle(Request::create('/admin/bans', 'POST', $add), []);
        self::assertSame(Response::HTTP_SEE_OTHER, $added->getStatusCode());

        $page = (string) $this->handler($session)->handle(Request::create('/admin/bans'), [])->getContent();
        self::assertStringContainsString('spam.example', $page);
        self::assertStringContainsString('Spam wave', $page);
        self::assertStringContainsString('When lifted', $page);

        $invalid = $this->handler($session)->handle(Request::create('/admin/bans', 'POST', [
            'value' => '<b>bad</b>',
        ] + $add), []);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getStatusCode());
        self::assertStringNotContainsString('<b>bad</b>', (string) $invalid->getContent(), 'escaped');
    }

    private function add(string $type, string $value): void
    {
        $this->bans->add(BanEntry::parse($type, $value), 'test', null, $this->users['admin'], $this->now);
    }

    /**
     * @param array<string, string> $input
     */
    private function violation(string $actor, array $input, ?string $ip = null): string
    {
        try {
            $this->service()->apply($this->as($actor), $input, $ip);
        } catch (ThreadRuleViolation $violation) {
            return $violation->diagnostic;
        }

        self::fail('expected a violation');
    }

    private function service(): ManageBans
    {
        return new ManageBans($this->bans, fn(): DateTimeImmutable => $this->now);
    }

    private function as(string $user): SubjectPermissions
    {
        $groups = new DbalUsergroupRepository($this->connection);

        return $this->resolver()->user($this->users[$user], $groups->groupIdsOf($this->users[$user]));
    }

    private function subjectOf(string $user, string $ip): SubjectPermissions
    {
        return $this->subjects($this->session($user))->of(Request::create('/', server: ['REMOTE_ADDR' => $ip]));
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
            null,
            $this->bans,
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

    private function login(string $user, string $ip, string $password = self::PASSWORD): Response
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
            null,
            $this->bans,
        );

        return $handler->handle(Request::create('/login', 'POST', [
            'identifier' => $user,
            'password' => $password,
        ], server: ['REMOTE_ADDR' => $ip]), []);
    }

    private function handler(ArraySession $session): BanListHandler
    {
        return new BanListHandler(
            self::translator(),
            self::views(),
            $this->subjects($session),
            $this->service(),
            new AdminSession($session),
            new CsrfTokens($session),
            self::router(),
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
