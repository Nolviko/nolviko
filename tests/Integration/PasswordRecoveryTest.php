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
use Nolviko\Core\Events\OutboxLease;
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
use Nolviko\Modules\Users\Domain\AccountMail;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\PasswordResetHandler;
use Nolviko\Modules\Users\Infrastructure\DbalRememberTokens;
use Nolviko\Modules\Users\Infrastructure\DbalResetTokens;
use Nolviko\Modules\Users\Infrastructure\DbalSessionEpochs;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Modules\Users\Infrastructure\PasswordRecovery;
use Nolviko\Modules\Users\Infrastructure\ResetMailQueue;
use Nolviko\Modules\Users\Infrastructure\TemplatedAccountMail;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\CapturingTransport;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M02.05 on MySQL 8.4: a reset request is answered alike for every address and limited per network and per
 * account; the link is valid for an hour and once; resetting changes the password, uses up the other links,
 * deletes remember-me tokens and ends every session; the pages are `no-store` and `no-referrer`.
 */
final class PasswordRecoveryTest extends TestCase
{
    private const OLD = 'old harbour lantern 17';

    private const NEW = 'fresh meadow compass 93';

    private const SITE = 'https://forum.example.com';

    private Connection $connection;

    private CapturingTransport $transport;

    private DateTimeImmutable $now;

    private int $alice;

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
        $users->create('Pending', 'pending@example.com', $hash, UserStatus::PendingVerification);
        $this->transport = new CapturingTransport();
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testRequestsAreAnsweredAlikeAndLimited(): void
    {
        $answers = [];

        foreach (['alice@example.com', 'nobody@example.com', 'pending@example.com', 'not an address'] as $i => $email) {
            $request = Request::create('/password/forgot', 'POST', ['email' => $email], server: [
                'REMOTE_ADDR' => '192.0.2.' . ($i + 1),
            ]);
            $answers[] = $this->forgot()->handle($request, []);
        }

        foreach ($answers as $answer) {
            self::assertSame(Response::HTTP_SEE_OTHER, $answer->getStatusCode());
            self::assertSame('/password/forgot?sent=1', $answer->headers->get('Location'));
        }

        self::assertCount(1, $this->transport->sent, 'only the active account gets a mail');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM password_reset_tokens'));
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            (string) $this->connection->fetchOne('SELECT requested_network FROM password_reset_tokens'),
            'the network is stored hashed',
        );

        $limited = $this->forgot()->handle(Request::create('/password/forgot', 'POST', [
            'email' => 'alice@example.com',
        ], server: ['REMOTE_ADDR' => '192.0.2.1']), []);
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $limited->getStatusCode());
        self::assertNotNull($limited->headers->get('Retry-After'));

        $otherNetwork = $this->recovery($this->now->modify('+2 minutes'))->request('alice@example.com', '203.0.113.5');
        self::assertSame(0, $otherNetwork);
        self::assertCount(1, $this->transport->sent, 'one mail per account every 5 minutes, silently');
        $later = $this->recovery($this->now->modify('+6 minutes'));
        self::assertSame(0, $later->request('alice@example.com', '203.0.113.6'));
        self::assertCount(2, $this->transport->sent);
    }

    public function testMailFailureDoesNotDiscloseAccountExistence(): void
    {
        $this->transport = new CapturingTransport('unreachable');
        $answers = [];

        foreach (['alice@example.com', 'unknown@example.com'] as $i => $email) {
            $answers[] = $this->forgot()->handle(Request::create('/password/forgot', 'POST', [
                'email' => $email,
            ], server: ['REMOTE_ADDR' => '192.0.2.' . ($i + 1)]), []);
        }

        self::assertSame(Response::HTTP_SEE_OTHER, $answers[0]->getStatusCode());
        self::assertSame($answers[0]->getStatusCode(), $answers[1]->getStatusCode());
        self::assertSame($answers[0]->headers->get('Location'), $answers[1]->headers->get('Location'));
    }

    public function testResetRevokesPendingEmailChanges(): void
    {
        $token = str_repeat('a', 43);
        $this->connection->insert('email_change_tokens', [
            'user_id' => $this->alice,
            'new_email' => 'new@example.com',
            'token_hash' => hash('sha256', $token),
            'expires_at' => $this->now->modify('+1 day')->format('Y-m-d H:i:s.u'),
            'created_at' => $this->now->format('Y-m-d H:i:s.u'),
        ]);
        $reset = (new DbalResetTokens($this->connection))->issue($this->alice, null, $this->now);
        self::assertTrue($this->recovery($this->now)->reset($reset, self::NEW, self::NEW));
        self::assertSame(0, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM email_change_tokens WHERE used_at IS NULL',
        ));
    }

    public function testResetChangesThePasswordAndEndsEverySession(): void
    {
        $this->recovery($this->now)->request('alice@example.com', '192.0.2.1');
        $token = $this->tokenFromMail(0);
        $this->recovery($this->now->modify('+6 minutes'))->request('alice@example.com', '192.0.2.2');
        $second = $this->tokenFromMail(1);

        $oldSession = new ArraySession();
        $alice = (new DbalUserRepository($this->connection))->findById($this->alice);
        self::assertNotNull($alice);
        $this->auth($oldSession)->login($alice, false, Request::create('/'));
        (new DbalRememberTokens($this->connection))->issue($this->alice);
        self::assertSame($this->alice, $this->auth($oldSession)->userId(Request::create('/')));

        $form = $this->reset()->handle(Request::create('/password/reset?token=' . $token), []);
        self::assertSame(Response::HTTP_OK, $form->getStatusCode());
        self::assertStringContainsString('no-store', (string) $form->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $form->headers->get('Referrer-Policy'));
        self::assertStringContainsString('name="token" value="' . $token . '"', (string) $form->getContent());

        $weak = $this->reset()->handle($this->post($token, 'short', 'short'), []);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $weak->getStatusCode());
        self::assertStringContainsString('at least 8 characters', (string) $weak->getContent());
        self::assertTrue($this->recovery($this->now)->isValid($token), 'a refused password keeps the link');

        $done = $this->reset()->handle($this->post($token, self::NEW, self::NEW), []);
        self::assertSame(Response::HTTP_OK, $done->getStatusCode());
        self::assertStringContainsString('You were logged out everywhere', (string) $done->getContent());

        $hash = (string) $this->connection->fetchOne('SELECT password_hash FROM users WHERE id = ?', [$this->alice]);
        self::assertTrue(password_verify(self::NEW, $hash));
        self::assertFalse(password_verify(self::OLD, $hash));
        self::assertNull($this->auth($oldSession)->userId(Request::create('/')), 'the old session has ended');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM remember_tokens'));
        self::assertFalse($this->recovery($this->now)->isValid($token), 'single use');
        self::assertFalse($this->recovery($this->now)->isValid($second), 'the other open link is used up too');
        self::assertSame(
            Response::HTTP_NOT_FOUND,
            $this->reset()->handle($this->post($token, self::NEW, self::NEW), [])->getStatusCode(),
        );

        $fresh = new ArraySession();
        $this->auth($fresh)->login($alice, false, Request::create('/'));
        self::assertSame($this->alice, $this->auth($fresh)->userId(Request::create('/')), 'new logins work');
    }

    public function testExpiredAndTamperedLinksAreRefused(): void
    {
        $this->recovery($this->now)->request('alice@example.com', '192.0.2.1');
        $token = $this->tokenFromMail(0);
        $tampered = substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A');

        self::assertTrue($this->recovery($this->now->modify('+59 minutes'))->isValid($token));
        self::assertFalse($this->recovery($this->now->modify('+61 minutes'))->isValid($token), 'expired');
        self::assertFalse($this->recovery($this->now->modify('+61 minutes'))->reset($token, self::NEW, self::NEW));
        self::assertFalse($this->recovery($this->now)->isValid($tampered));
        self::assertFalse($this->recovery($this->now)->isValid(''));

        $invalid = $this->reset()->handle(Request::create('/password/reset?token=%3Cscript%3E'), []);
        self::assertSame(Response::HTTP_NOT_FOUND, $invalid->getStatusCode());
        self::assertSame('no-referrer', $invalid->headers->get('Referrer-Policy'));
        self::assertStringNotContainsString('<script>', (string) $invalid->getContent());
    }

    public function testAllDevicesLogoutRevokesAnotherOpenSession(): void
    {
        $request = Request::create('/');
        $user = (new DbalUserRepository($this->connection))->findById($this->alice);
        $first = $this->auth(new ArraySession());
        $second = new ArraySession();
        $first->login($user, false, $request);
        $this->auth($second)->login($user, true, $request);
        $first->logout($request, true);
        self::assertNull($this->auth($second)->userId($request));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM remember_tokens'));
    }

    public function testQueuedRequestsDeliverOnlyWhileTheOriginalTokenIsValid(): void
    {
        $queue = new ResetMailQueue($this->connection, new Secret(str_repeat('k', 32)));
        $recovery = $this->recovery($this->now, $queue);
        $recovery->request('alice@example.com', '192.0.2.1');
        $recovery->request('unknown@example.com', '203.0.113.1');
        self::assertCount(0, $this->transport->sent);
        $jobs = $this->connection->fetchAllAssociative('SELECT id, payload FROM outbox_events WHERE event_type = ? ORDER BY id',
            [ResetMailQueue::EVENT]);
        self::assertCount(2, $jobs);
        $mail = $this->createMock(AccountMail::class);
        $token = null;
        $mail->expects(self::once())->method('passwordReset')->willReturnCallback(
            static function (string $to, string $name, string $value) use (&$token): void {
                self::assertSame('alice@example.com', $to);
                $token = $value;
            }
        );
        foreach ($jobs as $job) {
            $lease = new OutboxLease((int) $job['id'], ResetMailQueue::EVENT, 0, $job['payload'], '', 0, 1);
            $queue->deliver($lease, $mail);
        }
        self::assertIsString($token);
        self::assertStringNotContainsString($token, $jobs[0]['payload']);
        self::assertTrue($recovery->reset($token, self::NEW, self::NEW));
        $queue->deliver(new OutboxLease(1, ResetMailQueue::EVENT, 0, $jobs[0]['payload'], '', 0, 2), $mail);
    }

    private function post(string $token, string $password, string $confirmation): Request
    {
        return Request::create('/password/reset', 'POST', [
            'token' => $token,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ]);
    }

    private function tokenFromMail(int $index): string
    {
        $raw = quoted_printable_decode($this->transport->sent[$index]->toString());
        self::assertSame(
            1,
            preg_match('~' . preg_quote(self::SITE, '~') . '/password/reset\?token=([A-Za-z0-9_-]{43})~', $raw, $match),
        );

        return $match[1];
    }

    private function recovery(DateTimeImmutable $now, ?ResetMailQueue $queue = null): PasswordRecovery
    {
        $settings = new MailSettings(fromAddress: 'forum@example.com');
        $mailer = new SymfonyMailer($this->transport, $settings, new NullLogger());
        $composer = new MailComposer(self::views(), self::translator());

        return new PasswordRecovery(
            new DbalUserRepository($this->connection),
            new PasswordHasher(),
            new DbalResetTokens($this->connection),
            new TemplatedAccountMail($mailer, $composer, self::router(), self::SITE),
            new RateBuckets($this->connection),
            static fn(): DateTimeImmutable => $now,
            queue: $queue,
        );
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

    private function forgot(): PasswordResetHandler
    {
        return $this->handler(false);
    }

    private function reset(): PasswordResetHandler
    {
        return $this->handler(true);
    }

    private function handler(bool $reset): PasswordResetHandler
    {
        $session = new ArraySession();

        return new PasswordResetHandler(
            self::translator(),
            self::views(),
            $this->auth($session),
            $this->recovery($this->now),
            new CsrfTokens($session),
            self::router(),
            $reset,
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
