<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Users;

use DateTimeImmutable;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Users\Domain\Authenticator;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\LoginHandler;
use Nolviko\Modules\Users\Http\LogoutHandler;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryLoginAttempts;
use Nolviko\Tests\Support\MemoryUsers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M02.04 login, logout, session regeneration and remember-me at handler level (CSRF is CsrfGuard's job,
 * tested in Unit\Http\CsrfGuardTest; the DBAL token store in Integration\RememberTokensTest).
 */
final class LoginFlowTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private MemoryUsers $users;

    private ArraySession $session;

    private ResponseCookies $cookies;

    private PasswordHasher $hasher;

    private MemoryLoginAttempts $attempts;

    protected function setUp(): void
    {
        $this->users = new MemoryUsers();
        $this->session = new ArraySession();
        $this->cookies = new ResponseCookies();
        $this->hasher = new PasswordHasher();
        $this->attempts = new MemoryLoginAttempts();
        $this->users->create('Deniz', 'deniz@example.com', $this->hasher->hash(self::PASSWORD), UserStatus::Active);
    }

    public function testUnknownAccountAndWrongPasswordLookTheSame(): void
    {
        $started = hrtime(true);
        $unknown = $this->login(['identifier' => 'nobody', 'password' => self::PASSWORD]);
        $unknownTime = hrtime(true) - $started;
        $started = hrtime(true);
        $wrong = $this->login(['identifier' => 'deniz', 'password' => 'not the password']);
        $wrongTime = hrtime(true) - $started;

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $unknown->getStatusCode());
        self::assertSame($wrong->getStatusCode(), $unknown->getStatusCode());
        self::assertSame(self::message($wrong), self::message($unknown));
        self::assertStringContainsString('password is not correct.', self::message($wrong));
        self::assertGreaterThan(0.4, $unknownTime / $wrongTime, 'an unknown account still verifies a hash');
        self::assertNull($this->session->get('_user_id'));
    }

    public function testSuccessfulLoginRegeneratesTheSessionAndRotatesCsrf(): void
    {
        $csrf = new CsrfTokens($this->session);
        $beforeLogin = $csrf->token();
        $response = $this->login(['identifier' => 'DENIZ@example.com', 'password' => self::PASSWORD]);

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame('/', $response->headers->get('Location'));
        self::assertSame(1, $this->session->regenerations, 'session fixation: a new ID after login');
        self::assertFalse($csrf->isValid($beforeLogin), 'pre-login form tokens no longer work');
        self::assertSame(1, $this->session->get('_user_id'));
        self::assertSame([], $this->cookieValues(), 'no remember cookie unless asked for');
    }

    /**
     * @return array<string, array{0: UserStatus, 1: string}>
     */
    public static function inactiveStatuses(): array
    {
        return [
            'unverified' => [UserStatus::PendingVerification, 'Please confirm your email address first.'],
            'pending approval' => [UserStatus::PendingApproval, 'waiting for approval'],
            'suspended' => [UserStatus::Suspended, 'This account has been suspended.'],
        ];
    }

    #[DataProvider('inactiveStatuses')]
    public function testInactiveAccountsCannotLogIn(UserStatus $status, string $message): void
    {
        $this->users->updateStatus(1, $status);
        $response = $this->login(['identifier' => 'deniz', 'password' => self::PASSWORD]);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString($message, self::message($response));
        self::assertNull($this->session->get('_user_id'));
    }

    public function testLoginUpgradesAnOldHash(): void
    {
        $this->users->updatePasswordHash(1, (new PasswordHasher(argon2: false))->hash(self::PASSWORD));
        $this->login(['identifier' => 'deniz', 'password' => self::PASSWORD]);

        self::assertStringStartsWith('$argon2id$', $this->users->findById(1)?->passwordHash ?? '');
    }

    public function testFormNeverEchoesThePasswordAndEscapesTheIdentifier(): void
    {
        $response = $this->login(['identifier' => '"><b>x', 'password' => 'Secret-Echo-Check']);
        $html = (string) $response->getContent();

        self::assertStringNotContainsString('Secret-Echo-Check', $html);
        self::assertStringNotContainsString('<b>x', $html);
        self::assertStringContainsString('autocomplete="current-password"', $html);
    }

    public function testRememberMeLogsInAgainAndRotatesTheToken(): void
    {
        $this->login(['identifier' => 'deniz', 'password' => self::PASSWORD, 'remember' => '1']);
        $cookie = $this->cookieValues()[AuthSession::REMEMBER_COOKIE] ?? '';

        self::assertNotSame('', $cookie);

        // A new browser session: no session data, only the cookie.
        $this->session = new ArraySession();
        $userId = $this->auth()->userId(self::withCookie($cookie));
        $rotated = $this->cookieValues()[AuthSession::REMEMBER_COOKIE] ?? '';

        self::assertSame(1, $userId);
        self::assertSame(1, $this->session->regenerations);
        self::assertNotSame($cookie, $rotated);

        $this->session = new ArraySession();
        self::assertNull($this->auth()->userId(self::withCookie($cookie)), 'a used token is gone');
        self::assertCount(1, $this->users->tokens, 'only the rotated token remains');
    }

    public function testKnownSelectorWithWrongValidatorRevokesEveryToken(): void
    {
        $cookie = $this->users->issue(1);
        $this->users->issue(1);
        $forged = explode(':', $cookie)[0] . ':' . str_repeat('0', 32);

        self::assertNull($this->auth()->userId(self::withCookie($forged)));
        self::assertSame([], $this->users->tokens, 'a copied selector with a guessed validator looks like theft');
        self::assertTrue($this->cookieObjects()[AuthSession::REMEMBER_COOKIE]->isCleared());
    }

    public function testLogoutInvalidatesTheSessionAndRevokesTokens(): void
    {
        $this->login(['identifier' => 'deniz', 'password' => self::PASSWORD, 'remember' => '1']);
        $cookie = $this->cookieValues()[AuthSession::REMEMBER_COOKIE];
        $other = $this->users->issue(1);
        $logout = new LogoutHandler($this->auth(), self::router());
        $request = Request::create('/logout', 'POST', cookies: [AuthSession::REMEMBER_COOKIE => $cookie]);
        $response = $logout->handle($request, []);
        $cleared = $this->cookieObjects()[AuthSession::REMEMBER_COOKIE];

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertNull($this->session->get('_user_id'));
        self::assertTrue($cleared->isCleared());
        self::assertSame([explode(':', $other)[0]], array_keys($this->users->tokens), 'only this device logged out');

        $this->login(['identifier' => 'deniz', 'password' => self::PASSWORD]);
        $logout->handle(Request::create('/logout', 'POST', ['everywhere' => '1']), []);

        self::assertSame([], $this->users->tokens, 'logout everywhere revokes all tokens');
    }

    public function testThrottledAccountIsRefusedEvenWithTheRightPassword(): void
    {
        for ($i = 0; $i < LoginThrottle::ACCOUNT_LIMIT; ++$i) {
            $this->login(['identifier' => 'deniz', 'password' => 'wrong-' . $i]);
        }

        $response = $this->login(['identifier' => 'deniz', 'password' => self::PASSWORD]);

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $response->getStatusCode());
        self::assertSame('30', $response->headers->get('Retry-After'));
        self::assertStringContainsString('Try again in 1 minute.', self::message($response));
        self::assertNull($this->session->get('_user_id'));
    }

    public function testLoggedInVisitorIsSentHome(): void
    {
        $this->login(['identifier' => 'deniz', 'password' => self::PASSWORD]);

        self::assertSame('/', $this->handler()->handle(Request::create('/login'), [])->headers->get('Location'));
    }

    /**
     * @param array<string, string> $fields
     */
    private function login(array $fields): Response
    {
        return $this->handler()->handle(Request::create('/login', 'POST', $fields), []);
    }

    private function handler(): LoginHandler
    {
        $app = dirname(__DIR__, 3);
        $translator = TranslatorFactory::create($app . '/translations', 'en');

        return new LoginHandler(
            $translator,
            TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-no-storage', $translator),
            new Authenticator($this->users, $this->hasher),
            $this->auth(),
            new CsrfTokens($this->session),
            self::router(),
            new LoginThrottle(
                $this->attempts,
                clock: static fn(): DateTimeImmutable => new DateTimeImmutable('2026-11-04 12:00:00 UTC'),
                random: static fn(): int => 2,
            ),
        );
    }

    private function auth(): AuthSession
    {
        return new AuthSession($this->session, new CsrfTokens($this->session), $this->users, $this->cookies);
    }

    /**
     * @return array<string, Cookie>
     */
    private function cookieObjects(): array
    {
        $response = $this->cookies->apply(new Response());
        $cookies = [];

        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }

        return $cookies;
    }

    /**
     * @return array<string, string>
     */
    private function cookieValues(): array
    {
        return array_map(static fn(Cookie $cookie): string => (string) $cookie->getValue(), $this->cookieObjects());
    }

    private static function withCookie(string $cookie): Request
    {
        return Request::create('/', cookies: [AuthSession::REMEMBER_COOKIE => $cookie]);
    }

    private static function message(Response $response): string
    {
        preg_match('~<p role="alert">(.*?)</p>~s', (string) $response->getContent(), $match);

        return $match[1] ?? '';
    }

    private static function router(): Router
    {
        return new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
    }
}
