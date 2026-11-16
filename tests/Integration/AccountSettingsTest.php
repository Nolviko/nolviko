<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\MailSettings;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\RateBuckets;
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
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AccountHandler;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Infrastructure\AccountSettings;
use Nolviko\Modules\Users\Infrastructure\DbalRememberTokens;
use Nolviko\Modules\Users\Infrastructure\DbalSessionEpochs;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Modules\Users\Infrastructure\TemplatedAccountMail;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\CapturingTransport;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M02.16: changing one's password or address needs the current password and is paced; a new password ends the
 * other sessions and remember-me tokens but keeps the current one; a new address applies only after its link,
 * the old address gets a notice, and nothing in a request can aim at another account.
 */
final class AccountSettingsTest extends TestCase
{
    private const OLD = 'old harbour lantern 17';

    private const NEW = 'fresh meadow compass 93';

    private const SITE = 'https://forum.example.com';

    private Connection $connection;

    private CapturingTransport $transport;

    private DateTimeImmutable $now;

    private int $alice;

    private int $bob;

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
        $hash = (new PasswordHasher())->hash(self::OLD);
        $this->alice = $users->create('Alice', 'alice@example.com', $hash, UserStatus::Active)->id;
        $this->bob = $users->create('Bob', 'bob@example.com', $hash, UserStatus::Active)->id;
        $this->transport = new CapturingTransport();
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testPasswordChangeKeepsThisSessionAndEndsTheOthers(): void
    {
        $other = $this->signedIn($this->alice);
        $current = $this->signedIn($this->alice);
        (new DbalRememberTokens($this->connection))->issue($this->alice);

        $wrong = $this->handler($current, AccountHandler::PASSWORD)->handle($this->post('/account/password', [
            'current_password' => 'not it',
            'password' => self::NEW,
            'password_confirmation' => self::NEW,
        ]), []);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $wrong->getStatusCode());
        self::assertStringContainsString('The current password is not correct.', (string) $wrong->getContent());

        $done = $this->handler($current, AccountHandler::PASSWORD, $this->now->modify('+10 seconds'))->handle(
            $this->post('/account/password', [
                'current_password' => self::OLD,
                'password' => self::NEW,
                'password_confirmation' => self::NEW,
            ]),
            [],
        );

        self::assertSame(Response::HTTP_SEE_OTHER, $done->getStatusCode());
        self::assertSame('/account?done=password', $done->headers->get('Location'));
        $hash = (string) $this->connection->fetchOne('SELECT password_hash FROM users WHERE id = ?', [$this->alice]);
        self::assertTrue(password_verify(self::NEW, $hash));
        self::assertSame($this->alice, $this->auth($current)->userId(Request::create('/')), 'this session stays');
        self::assertNull($this->auth($other)->userId(Request::create('/')), 'other sessions end');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM remember_tokens'));
    }

    public function testPasswordChangeRevokesPendingEmailChange(): void
    {
        $settings = $this->settings($this->now);
        $settings->requestEmailChange($this->alice, self::OLD, 'new@example.com');
        $token = $this->token(0);
        $later = $this->settings($this->now->modify('+10 seconds'));
        $later->changePassword($this->alice, self::OLD, self::NEW, self::NEW);

        self::assertFalse($later->isValidChange($token));
        self::assertNull($later->confirmEmailChange($token));
        self::assertSame('alice@example.com', $this->email($this->alice));
    }

    public function testAttemptsArePacedAndPoliciesApply(): void
    {
        $settings = $this->settings($this->now);

        self::assertSame(['current_password_invalid'], $this->rejected(
            fn() => $settings->changePassword($this->alice, 'wrong', self::NEW, self::NEW),
        ));

        try {
            $settings->changePassword($this->alice, self::OLD, self::NEW, self::NEW);
            self::fail('expected the pace limit');
        } catch (RegistrationThrottled $throttled) {
            self::assertGreaterThan(0, $throttled->seconds);
        }

        $later = $this->settings($this->now->modify('+10 seconds'));
        self::assertSame(['password_too_short'], $this->rejected(
            fn() => $later->changePassword($this->alice, self::OLD, 'short', 'short'),
        ));
        self::assertSame(['email_invalid'], $this->rejected(
            fn() => $this->settings($this->now->modify('+20 seconds'))
                ->requestEmailChange($this->alice, self::OLD, 'not an address'),
        ));
        self::assertSame(['email_unchanged'], $this->rejected(
            fn() => $this->settings($this->now->modify('+30 seconds'))
                ->requestEmailChange($this->alice, self::OLD, 'ALICE@example.com'),
        ));
    }

    public function testEmailChangeAppliesOnlyAfterTheLink(): void
    {
        $session = $this->signedIn($this->alice);
        $requested = $this->handler($session, AccountHandler::EMAIL)->handle($this->post('/account/email', [
            'current_password' => self::OLD,
            'email' => 'alice.new@example.com',
            'user_id' => (string) $this->bob,
        ]), []);

        self::assertSame(Response::HTTP_SEE_OTHER, $requested->getStatusCode());
        self::assertSame('alice@example.com', $this->email($this->alice), 'nothing changes before the link');
        self::assertSame('bob@example.com', $this->email($this->bob), 'a user ID in the request is ignored');
        self::assertCount(2, $this->transport->sent);
        self::assertSame('alice.new@example.com', $this->recipient(0));
        self::assertSame('alice@example.com', $this->recipient(1), 'the old address gets a notice');

        $token = $this->token(0);
        $confirm = $this->handler(new ArraySession(), AccountHandler::CONFIRM)
            ->handle(Request::create('/account/email/confirm?token=' . $token), []);
        self::assertSame(Response::HTTP_OK, $confirm->getStatusCode());
        self::assertSame('alice@example.com', $this->email($this->alice), 'GET does not use the link');

        $done = $this->handler(new ArraySession(), AccountHandler::CONFIRM)
            ->handle($this->post('/account/email/confirm', ['token' => $token]), []);
        self::assertSame(Response::HTTP_OK, $done->getStatusCode());
        self::assertSame('alice.new@example.com', $this->email($this->alice));
        self::assertSame(
            'alice.new@example.com',
            $this->connection->fetchOne('SELECT email_normalized FROM users WHERE id = ?', [$this->alice]),
        );

        $again = $this->handler(new ArraySession(), AccountHandler::CONFIRM)
            ->handle($this->post('/account/email/confirm', ['token' => $token]), []);
        self::assertSame(Response::HTTP_NOT_FOUND, $again->getStatusCode(), 'single use');
    }

    public function testTakenAddressesAndRacesRevealNothing(): void
    {
        $settings = $this->settings($this->now);
        $settings->requestEmailChange($this->alice, self::OLD, 'bob@example.com');
        self::assertSame([], $this->transport->sent, 'another account\'s address gets no mail');

        $this->settings($this->now->modify('+10 seconds'))
            ->requestEmailChange($this->alice, self::OLD, 'shared@example.com');
        $token = $this->token(0);
        $this->connection->executeStatement(
            "UPDATE users SET email = 'shared@example.com', email_normalized = 'shared@example.com' WHERE id = ?",
            [$this->bob],
        );

        self::assertNull($settings->confirmEmailChange($token), 'taken meanwhile');
        self::assertSame('alice@example.com', $this->email($this->alice));
        self::assertNull($this->settings($this->now->modify('+25 hours'))->confirmEmailChange('x'));

        $guest = $this->handler(new ArraySession(), AccountHandler::PAGE)->handle(Request::create('/account'), []);
        self::assertSame('/login', $guest->headers->get('Location'));
    }

    /**
     * @param callable(): mixed $action
     *
     * @return list<string>
     */
    private function rejected(callable $action): array
    {
        try {
            $action();
        } catch (RegistrationRejected $rejected) {
            return $rejected->diagnostics;
        }

        self::fail('expected a rejection');
    }

    private function email(int $userId): string
    {
        return (string) $this->connection->fetchOne('SELECT email FROM users WHERE id = ?', [$userId]);
    }

    private function recipient(int $index): string
    {
        return $this->transport->sent[$index]->getEnvelope()->getRecipients()[0]->getAddress();
    }

    private function token(int $index): string
    {
        $raw = quoted_printable_decode($this->transport->sent[$index]->toString());
        $pattern = '~' . preg_quote(self::SITE, '~') . '/account/email/confirm\?token=([A-Za-z0-9_-]{43})~';
        self::assertSame(1, preg_match($pattern, $raw, $match));

        return $match[1];
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(string $path, array $fields): Request
    {
        return Request::create($path, 'POST', $fields);
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
        return new AuthSession(
            $session,
            new CsrfTokens($session),
            new DbalRememberTokens($this->connection),
            new ResponseCookies(),
            new DbalSessionEpochs($this->connection),
        );
    }

    private function settings(DateTimeImmutable $now): AccountSettings
    {
        $mailer = new SymfonyMailer(
            $this->transport,
            new MailSettings(fromAddress: 'forum@example.com'),
            new NullLogger(),
        );

        return new AccountSettings(
            $this->connection,
            new DbalUserRepository($this->connection),
            new PasswordHasher(),
            new TemplatedAccountMail(
                $mailer,
                new MailComposer(self::views(), self::translator()),
                self::router(),
                self::SITE,
            ),
            new RateBuckets($this->connection),
            static fn(): DateTimeImmutable => $now,
        );
    }

    private function handler(ArraySession $session, string $mode, ?DateTimeImmutable $now = null): AccountHandler
    {
        return new AccountHandler(
            self::translator(),
            self::views(),
            $this->auth($session),
            new DbalUserRepository($this->connection),
            $this->settings($now ?? $this->now),
            new CsrfTokens($session),
            self::router(),
            $mode,
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
