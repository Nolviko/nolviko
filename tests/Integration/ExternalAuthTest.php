<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\MailSettings;
use Nolviko\Core\Config\RegistrationRules;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Events\Outbox;
use Nolviko\Core\Extensions\IdentityProvider;
use Nolviko\Core\Extensions\IdentityProviders;
use Nolviko\Core\Extensions\SecondFactorProviders;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Mail\MailComposer;
use Nolviko\Core\Mail\SymfonyMailer;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Moderation\Infrastructure\DbalBanList;
use Nolviko\Modules\Moderation\Infrastructure\DbalUserSanctions;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\SecondFactors;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\ExternalAuthHandler;
use Nolviko\Modules\Users\Http\SecondFactorLogin;
use Nolviko\Modules\Users\Infrastructure\AccountRegistration;
use Nolviko\Modules\Users\Infrastructure\DbalLoginAttempts;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Modules\Users\Infrastructure\DbalVerificationTokens;
use Nolviko\Modules\Users\Infrastructure\ExternalIdentities;
use Nolviko\Modules\Users\Infrastructure\TemplatedAccountMail;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\CapturingTransport;
use Nolviko\Tests\Support\CountingSecondFactor;
use Nolviko\Tests\Support\FakeIdentityProvider;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing in with an outside account (2026-11-15): state and PKCE, sign-up only with a new verified address,
 * no takeover of existing accounts, linked sign-in with bans and the second step, linking and unlinking.
 */
final class ExternalAuthTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private Connection $connection;

    private int $alice;

    private FakeIdentityProvider $fake;

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
        $hash = (new PasswordHasher())->hash(self::PASSWORD);
        $this->alice = (new DbalUserRepository($this->connection))
            ->create('Alice', 'alice@example.com', $hash, UserStatus::Active)->id;
        $this->fake = new FakeIdentityProvider();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testANewVerifiedAccountSignsUpWithAChosenName(): void
    {
        $session = new ArraySession();
        $back = $this->roundTrip($session, 'ok');
        self::assertSame('/register/external', $back->headers->get('Location'));

        $form = $this->handler(ExternalAuthHandler::REGISTER, $session)
            ->handle(Request::create('/register/external'), []);
        self::assertStringContainsString('value="Yeni Üye"', (string) $form->getContent());

        $done = $this->handler(ExternalAuthHandler::REGISTER, $session)
            ->handle(Request::create('/register/external', 'POST', ['username' => 'yeni_uye']), []);
        self::assertSame('/', $done->headers->get('Location'));
        $user = (new DbalUserRepository($this->connection))->findByEmail('new@example.com');
        self::assertNotNull($user);
        self::assertSame(UserStatus::Active, $user->status);
        self::assertSame($user->id, $this->auth($session)->userId(Request::create('/')));
        $linked = (new ExternalIdentities($this->connection))->forUser($user->id);
        self::assertSame(['fake/provider' => 'new@example.com'], $linked);
        self::assertSame(64, strlen((string) $this->fake->lastChallenge?->nonce));
        self::assertGreaterThan(40, strlen($this->fake->lastVerifier));

        // Next time the same outside account signs straight in.
        $again = new ArraySession();
        self::assertSame('/', $this->roundTrip($again, 'ok')->headers->get('Location'));
        self::assertSame($user->id, $this->auth($again)->userId(Request::create('/')));
    }

    public function testAnExistingAddressLinksOnlyWithThatAccountsPassword(): void
    {
        $this->fake->email = 'alice@example.com';
        $session = new ArraySession();

        $answer = $this->roundTrip($session, 'ok');
        self::assertSame('/login/link-account', $answer->headers->get('Location'));
        self::assertNull($this->auth($session)->userId(Request::create('/')), 'not signed in by the address alone');

        $link = $this->handler(ExternalAuthHandler::LINK, $session);
        $form = $link->handle(Request::create('/login/link-account'), []);
        self::assertStringContainsString('alice@example.com', (string) $form->getContent());
        $wrong = $link->handle(Request::create('/login/link-account', 'POST', ['password' => 'nope']), []);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $wrong->getStatusCode());
        self::assertSame([], (new ExternalIdentities($this->connection))->forUser($this->alice));

        $done = $link->handle(Request::create('/login/link-account', 'POST', ['password' => self::PASSWORD]), []);
        self::assertSame('/', $done->headers->get('Location'));
        self::assertSame($this->alice, $this->auth($session)->userId(Request::create('/')));
        self::assertSame(['fake/provider' => 'alice@example.com'], (new ExternalIdentities($this->connection))
            ->forUser($this->alice));

        // Next time Google alone signs Alice in.
        $again = new ArraySession();
        self::assertSame('/', $this->roundTrip($again, 'ok')->headers->get('Location'));
        self::assertSame($this->alice, $this->auth($again)->userId(Request::create('/')));
    }

    public function testFiveWrongPasswordsEndThePendingLink(): void
    {
        $this->fake->email = 'alice@example.com';
        $session = new ArraySession();
        $this->roundTrip($session, 'ok');
        $link = $this->handler(ExternalAuthHandler::LINK, $session);
        $last = null;
        for ($i = 0; $i < 5; ++$i) {
            $last = $link->handle(Request::create('/login/link-account', 'POST', ['password' => 'nope' . $i]), []);
        }

        self::assertSame('/login', $last?->headers->get('Location'));
        $late = $link->handle(Request::create('/login/link-account', 'POST', ['password' => self::PASSWORD]), []);
        self::assertSame('/login', $late->headers->get('Location'));
        self::assertSame([], (new ExternalIdentities($this->connection))->forUser($this->alice));
    }

    public function testStateMustMatchAndIsSingleUse(): void
    {
        $session = new ArraySession();
        $start = $this->handler(ExternalAuthHandler::START, $session)
            ->handle(Request::create('/login/external/fake'), ['provider' => 'fake']);
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);

        $forged = $this->handler(ExternalAuthHandler::CALLBACK, $session)->handle(
            Request::create('/login/external/fake/callback', 'GET', ['state' => 'forged', 'code' => 'ok']),
            ['provider' => 'fake'],
        );
        self::assertSame(Response::HTTP_BAD_REQUEST, $forged->getStatusCode());

        $replayed = $this->handler(ExternalAuthHandler::CALLBACK, $session)->handle(
            Request::create('/login/external/fake/callback', 'GET', ['state' => $query['state'], 'code' => 'ok']),
            ['provider' => 'fake'],
        );
        self::assertSame(Response::HTTP_BAD_REQUEST, $replayed->getStatusCode(), 'the forged try used up the state');
        self::assertSame(Response::HTTP_BAD_GATEWAY, $this->roundTrip(new ArraySession(), 'bad')->getStatusCode());
    }

    public function testLinkingThenSigningInWithTheSecondStep(): void
    {
        $session = $this->signedIn($this->alice);
        $start = $this->handler(ExternalAuthHandler::START, $session)
            ->handle(Request::create('/login/external/fake', 'POST'), ['provider' => 'fake']);
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $linked = $this->handler(ExternalAuthHandler::CALLBACK, $session)->handle(
            Request::create('/login/external/fake/callback', 'GET', ['state' => $query['state'], 'code' => 'ok']),
            ['provider' => 'fake'],
        );
        self::assertSame('/account?external=linked', $linked->headers->get('Location'));

        // The same outside account cannot be linked to someone else.
        $bob = (new DbalUserRepository($this->connection))
            ->create('Bob', 'bob@example.com', (new PasswordHasher())->hash(self::PASSWORD), UserStatus::Active)->id;
        $bobSession = $this->signedIn($bob);
        $start = $this->handler(ExternalAuthHandler::START, $bobSession)
            ->handle(Request::create('/login/external/fake', 'POST'), ['provider' => 'fake']);
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        self::assertSame(Response::HTTP_CONFLICT, $this->handler(ExternalAuthHandler::CALLBACK, $bobSession)->handle(
            Request::create('/login/external/fake/callback', 'GET', ['state' => $query['state'], 'code' => 'ok']),
            ['provider' => 'fake'],
        )->getStatusCode());

        // A member with a second step still has to pass it.
        $this->factors()->enroll($this->alice, 'acme.factor.code', '0');
        $fresh = new ArraySession();
        self::assertSame('/login/verify', $this->roundTrip($fresh, 'ok')->headers->get('Location'));
        self::assertNull($this->auth($fresh)->userId(Request::create('/')));

        // Unlinking needs the password.
        $unlink = $this->handler(ExternalAuthHandler::UNLINK, $session);
        $wrong = $unlink->handle(Request::create('/account/external', 'POST', [
            'provider' => 'fake', 'current_password' => 'nope',
        ]), []);
        self::assertSame('/account?external=password', $wrong->headers->get('Location'));
        $this->handler(ExternalAuthHandler::UNLINK, $session)->handle(Request::create('/account/external', 'POST', [
            'provider' => 'fake', 'current_password' => self::PASSWORD,
        ]), []);
        self::assertSame([], (new ExternalIdentities($this->connection))->forUser($this->alice));
    }

    public function testClosedRegistrationRefusesSignUp(): void
    {
        $session = new ArraySession();
        $answer = $this->roundTrip($session, 'ok', new RegistrationRules(mode: RegistrationRules::CLOSED));

        self::assertSame(Response::HTTP_FORBIDDEN, $answer->getStatusCode());
    }

    private function roundTrip(ArraySession $session, string $code, ?RegistrationRules $rules = null): Response
    {
        $start = $this->handler(ExternalAuthHandler::START, $session, $rules)
            ->handle(Request::create('/login/external/fake'), ['provider' => 'fake']);
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);

        return $this->handler(ExternalAuthHandler::CALLBACK, $session, $rules)->handle(
            Request::create('/login/external/fake/callback', 'GET', [
                'state' => $query['state'] ?? '', 'code' => $code,
            ]),
            ['provider' => 'fake'],
        );
    }

    private function handler(
        string $mode,
        ArraySession $session,
        ?RegistrationRules $rules = null,
    ): ExternalAuthHandler {
        $fake = $this->fake;
        $providers = new IdentityProviders(['fake.provider' => static fn(): IdentityProvider => $fake], [
            'fake.provider' => 'fake/provider',
        ]);
        $users = new DbalUserRepository($this->connection);
        $catalog = PermissionCatalog::core();

        return new ExternalAuthHandler(
            $mode,
            self::translator(),
            self::views(),
            $providers,
            new ExternalIdentities($this->connection),
            $this->auth($session),
            $session,
            new CsrfTokens($session),
            self::router(),
            new SiteUrl('https://forum.example'),
            $users,
            new PasswordHasher(),
            new LoginThrottle(new DbalLoginAttempts($this->connection), random: static fn(): int => 2),
            new ErrorResponder(self::translator()),
            fn(): AccountRegistration => $this->registration($rules ?? new RegistrationRules(networkSeconds: 0)),
            fn(): SecondFactorLogin => new SecondFactorLogin(
                $session,
                $this->factors(),
                new DbalUsergroupRepository($this->connection),
                new PermissionResolver(
                    $catalog,
                    new DbalPermissionRuleStore($this->connection, $catalog),
                    new DbalForumTree($this->connection),
                ),
            ),
            new DbalUserSanctions($this->connection, new Outbox($this->connection)),
            new DbalBanList($this->connection, new Outbox($this->connection)),
        );
    }

    private function factors(): SecondFactors
    {
        return new SecondFactors(
            $this->connection,
            new SecondFactorProviders(
                ['acme.factor.code' => static fn(): CountingSecondFactor => new CountingSecondFactor()],
                ['acme.factor.code' => 'acme/factor'],
            ),
            new Secret(str_repeat('k', 32)),
        );
    }

    private function registration(RegistrationRules $rules): AccountRegistration
    {
        $settings = new MailSettings(fromAddress: 'forum@example.com');
        $mailer = new SymfonyMailer(new CapturingTransport(), $settings, new NullLogger());

        return new AccountRegistration(
            $this->connection,
            new DbalUserRepository($this->connection),
            new DbalUsergroupRepository($this->connection),
            new PasswordHasher(),
            new DbalVerificationTokens($this->connection),
            new TemplatedAccountMail(
                $mailer,
                new MailComposer(self::views(), self::translator()),
                self::router(),
                null,
            ),
            new RateBuckets($this->connection),
            new DbalBanList($this->connection, new Outbox($this->connection)),
            null,
            $rules,
        );
    }

    private function signedIn(int $userId): ArraySession
    {
        $session = new ArraySession();
        $user = (new DbalUserRepository($this->connection))->findById($userId);
        self::assertNotNull($user);
        $this->auth($session)->login($user, false, Request::create('/'));

        return $session;
    }

    private function auth(ArraySession $session): AuthSession
    {
        return new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies());
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
