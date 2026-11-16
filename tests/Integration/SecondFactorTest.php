<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Extensions\SecondFactorProvider;
use Nolviko\Core\Extensions\SecondFactorProviders;
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
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Users\Domain\Authenticator;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\SecondFactors;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\LoginHandler;
use Nolviko\Modules\Users\Http\LoginVerifyHandler;
use Nolviko\Modules\Users\Http\SecondFactorLogin;
use Nolviko\Modules\Users\Http\SecondFactorSetupGuard;
use Nolviko\Modules\Users\Infrastructure\DbalLoginAttempts;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\CountingSecondFactor;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second sign-in step (2026-11-15, ADR-029): core storage, sealing, replay protection, recovery codes, the
 * password-only fallback for a disabled provider, the sign-in step and the group requirement.
 */
final class SecondFactorTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private const PROVIDER = 'acme.factor.code';

    private Connection $connection;

    private int $alice;

    private int $bob;

    /** @var list<array{int, string}> */
    private array $notices = [];

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
        $hash = (new PasswordHasher())->hash(self::PASSWORD);
        $this->alice = $users->create('Alice', 'alice@example.com', $hash, UserStatus::Active)->id;
        $this->bob = $users->create('Bob', 'bob@example.com', $hash, UserStatus::Active)->id;
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testEnrolmentIsSealedAndEveryCodeWorksOnce(): void
    {
        $factors = $this->factors();
        $codes = $factors->enroll($this->alice, self::PROVIDER, '0');

        self::assertCount(SecondFactors::RECOVERY_CODES, $codes);
        self::assertSame([self::PROVIDER], $factors->active($this->alice));
        self::assertSame([[$this->alice, 'enabled']], $this->notices);
        $stored = (string) $this->connection->fetchOne('SELECT state FROM user_second_factors');
        self::assertStringStartsWith('v1.', $stored);
        self::assertStringNotContainsString('"s":"0"', (string) base64_decode(substr($stored, 3)));

        self::assertTrue($factors->verify($this->alice, 'sign_in', 'code-1'));
        self::assertFalse($factors->verify($this->alice, 'sign_in', 'code-1'), 'replayed code');
        self::assertFalse($factors->verify($this->alice, 'sign_in', 'wrong'));
        self::assertTrue($factors->verify($this->alice, 'sign_in', 'code-2'));

        // Recovery codes: once each, spelled with or without the dash and in any case.
        self::assertTrue($factors->verify($this->alice, 'sign_in', strtoupper(str_replace('-', '', $codes[0]))));
        self::assertFalse($factors->verify($this->alice, 'sign_in', $codes[0]));
        self::assertSame(SecondFactors::RECOVERY_CODES - 1, $factors->recoveryLeft($this->alice));
        self::assertSame([], $factors->enroll($this->alice, self::PROVIDER, '5'), 'unused codes are kept');

        $fresh = $factors->newRecoveryCodes($this->alice);
        self::assertFalse($factors->verify($this->alice, 'sign_in', $codes[1]), 'old codes stop working');
        self::assertTrue($factors->verify($this->alice, 'sign_in', $fresh[0]));
    }

    public function testStateCannotBeMovedToAnotherMember(): void
    {
        $factors = $this->factors();
        $factors->enroll($this->alice, self::PROVIDER, '0');
        $factors->enroll($this->bob, self::PROVIDER, '0');
        $alice = $this->connection->fetchOne('SELECT state FROM user_second_factors WHERE user_id = ?', [$this->alice]);
        $this->connection->update('user_second_factors', ['state' => $alice], ['user_id' => $this->bob]);

        self::assertFalse($factors->verify($this->bob, 'sign_in', 'code-1'));
        self::assertTrue($factors->verify($this->alice, 'sign_in', 'code-1'));
    }

    public function testADisabledProviderFallsBackToThePasswordAndIsCounted(): void
    {
        $this->factors()->enroll($this->alice, self::PROVIDER, '0');
        $without = new SecondFactors($this->connection, new SecondFactorProviders(), new Secret(str_repeat('k', 32)));

        self::assertSame([], $without->active($this->alice));
        self::assertTrue($without->hasAny($this->alice));
        self::assertSame(1, $without->membersWithoutProvider());
        self::assertSame(0, $this->factors()->membersWithoutProvider());
        self::assertFalse($without->verify($this->alice, 'sign_in', 'code-1'));

        $without->reset($this->alice);
        self::assertFalse($without->hasAny($this->alice));
        self::assertSame(0, $without->recoveryLeft($this->alice));
    }

    public function testSignInWaitsForTheCodeAndFiveWrongCodesEndIt(): void
    {
        $this->factors()->enroll($this->alice, self::PROVIDER, '0');
        $session = new ArraySession();

        $response = $this->login($session)->handle($this->post('/login', [
            'identifier' => 'alice', 'password' => self::PASSWORD,
        ]), []);
        self::assertSame('/login/verify', $response->headers->get('Location'));
        self::assertNull($this->auth($session)->userId(Request::create('/')));

        $verify = $this->verifier($session);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $verify->handle(
            $this->post('/login/verify', ['code' => 'nope']),
            [],
        )->getStatusCode());
        $done = $verify->handle($this->post('/login/verify', ['code' => 'code-1']), []);
        self::assertSame('/', $done->headers->get('Location'));
        self::assertSame($this->alice, $this->auth($session)->userId(Request::create('/')));

        $other = new ArraySession();
        $this->login($other)->handle($this->post('/login', [
            'identifier' => 'alice', 'password' => self::PASSWORD,
        ]), []);
        $last = null;
        for ($i = 0; $i < SecondFactorLogin::MAX_TRIES; ++$i) {
            $last = $this->verifier($other)->handle($this->post('/login/verify', ['code' => 'nope']), []);
        }
        self::assertSame('/login', $last?->headers->get('Location'));
        self::assertSame('/login', $this->verifier($other)->handle(
            $this->post('/login/verify', ['code' => 'code-9']),
            [],
        )->headers->get('Location'), 'the pending sign-in is gone');
    }

    public function testAGroupRequirementKeepsTheMemberOnTheSetupPage(): void
    {
        $catalog = PermissionCatalog::core();
        (new DbalPermissionRuleStore($this->connection, $catalog))
            ->setGroupRule(BuiltInGroup::Registered->value, SecondFactorLogin::REQUIRED_PERMISSION, true);
        (new DbalUsergroupRepository($this->connection))->addMember($this->bob, BuiltInGroup::Registered->value);
        $session = new ArraySession();

        $this->login($session)->handle($this->post('/login', [
            'identifier' => 'bob', 'password' => self::PASSWORD,
        ]), []);
        self::assertSame($this->bob, $this->auth($session)->userId(Request::create('/')));

        $guard = new SecondFactorSetupGuard(
            static fn(): ArraySession => $session,
            fn(): AuthSession => $this->auth($session),
            fn(): SecondFactorLogin => $this->secondFactor($session),
            static fn(): Router => self::router(),
        );
        $held = $guard->guard(['_route' => CoreRoutes::HOME], Request::create('/'));
        self::assertSame('/account', $held?->headers->get('Location'));
        self::assertNull($guard->guard(['_route' => CoreRoutes::ACCOUNT], Request::create('/account')));
        self::assertNull($guard->guard(['_route' => CoreRoutes::LOGOUT], Request::create('/logout')));

        $this->factors()->enroll($this->bob, self::PROVIDER, '0');
        self::assertNull($guard->guard(['_route' => CoreRoutes::HOME], Request::create('/')), 'released');
    }

    private function factors(): SecondFactors
    {
        return new SecondFactors(
            $this->connection,
            new SecondFactorProviders(
                [self::PROVIDER => static fn(): SecondFactorProvider => new CountingSecondFactor()],
                [self::PROVIDER => 'acme/factor'],
            ),
            new Secret(str_repeat('k', 32)),
            function (int $userId, string $event): void {
                $this->notices[] = [$userId, $event];
            },
        );
    }

    private function secondFactor(ArraySession $session): SecondFactorLogin
    {
        $catalog = PermissionCatalog::core();

        return new SecondFactorLogin(
            $session,
            $this->factors(),
            new DbalUsergroupRepository($this->connection),
            new PermissionResolver(
                $catalog,
                new DbalPermissionRuleStore($this->connection, $catalog),
                new DbalForumTree($this->connection),
            ),
        );
    }

    private function login(ArraySession $session): LoginHandler
    {
        return new LoginHandler(
            self::translator(),
            self::views(),
            new Authenticator(new DbalUserRepository($this->connection), new PasswordHasher()),
            $this->auth($session),
            new CsrfTokens($session),
            self::router(),
            $this->throttle(),
            null,
            null,
            $this->secondFactor($session),
        );
    }

    private function verifier(ArraySession $session): LoginVerifyHandler
    {
        return new LoginVerifyHandler(
            self::translator(),
            self::views(),
            $this->secondFactor($session),
            $this->auth($session),
            new DbalUserRepository($this->connection),
            new CsrfTokens($session),
            self::router(),
            $this->throttle(),
        );
    }

    private function throttle(): LoginThrottle
    {
        return new LoginThrottle(new DbalLoginAttempts($this->connection), random: static fn(): int => 2);
    }

    private function auth(ArraySession $session): AuthSession
    {
        return new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies());
    }

    /** @param array<string, string> $fields */
    private function post(string $path, array $fields): Request
    {
        return Request::create($path, 'POST', $fields);
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
