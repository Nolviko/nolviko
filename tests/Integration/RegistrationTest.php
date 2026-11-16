<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\MailSettings;
use Nolviko\Core\Config\RegistrationRules;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Events\Outbox;
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
use Nolviko\Modules\Admin\Http\RegistrationSettingsHandler;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Moderation\Domain\BanEntry;
use Nolviko\Modules\Moderation\Infrastructure\DbalBanList;
use Nolviko\Modules\Users\Domain\Authenticator;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Nolviko\Modules\Users\Domain\NoChallenge;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\RegistrationChallenge;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\LoginHandler;
use Nolviko\Modules\Users\Http\QuestionChallenge;
use Nolviko\Modules\Users\Http\RegisterHandler;
use Nolviko\Modules\Users\Http\VerifyEmailHandler;
use Nolviko\Modules\Users\Http\WorkChallenge;
use Nolviko\Modules\Users\Infrastructure\AccountRegistration;
use Nolviko\Modules\Users\Infrastructure\DbalLoginAttempts;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Modules\Users\Infrastructure\DbalVerificationTokens;
use Nolviko\Modules\Users\Infrastructure\TemplatedAccountMail;
use Nolviko\Tests\Performance\BenchmarkHarness;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\CapturingTransport;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M02.03 on MySQL 8.4: registration creates a pending account and mails a link built from `app.url`; the token
 * is confirmed by POST only, once, within 24 hours and for the current address; an address with an account gets
 * the same answer and a notice mail instead; resending is limited; banned addresses get nothing.
 */
final class RegistrationTest extends TestCase
{
    private const PASSWORD = 'violet harbour lantern 42';

    private const SITE = 'https://forum.example.com';

    private Connection $connection;

    private CapturingTransport $transport;

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
        $this->transport = new CapturingTransport();
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testRegistrationVerificationAndLogin(): void
    {
        $response = $this->register('Alice', 'alice@example.com');

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame('/register?sent=1', $response->headers->get('Location'));

        $user = (new DbalUserRepository($this->connection))->findByEmail('alice@example.com');
        self::assertNotNull($user);
        self::assertSame(UserStatus::PendingVerification, $user->status);
        self::assertSame(
            [BuiltInGroup::Registered->value],
            (new DbalUsergroupRepository($this->connection))->groupIdsOf($user->id),
        );
        self::assertSame(
            64,
            strlen((string) $this->connection->fetchOne('SELECT token_hash FROM email_verification_tokens')),
        );

        $token = $this->tokenFromMail(0);
        self::assertStringNotContainsString(
            $token,
            (string) json_encode($this->connection->fetchAllAssociative('SELECT * FROM email_verification_tokens')),
            'only the hash is stored',
        );
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->login('Alice')->getStatusCode(), 'pending');

        $confirm = $this->verify()->handle(Request::create('/verify-email?token=' . $token), []);
        self::assertSame(Response::HTTP_OK, $confirm->getStatusCode());
        self::assertStringContainsString('name="token" value="' . $token . '"', (string) $confirm->getContent());
        self::assertSame(
            'pending_verification',
            $this->accountStatus('alice@example.com'),
            'GET does not use the token',
        );

        $done = $this->verify()->handle(Request::create('/verify-email', 'POST', ['token' => $token]), []);
        self::assertSame(Response::HTTP_OK, $done->getStatusCode());
        self::assertStringContainsString('Your email address is confirmed', (string) $done->getContent());
        self::assertSame('active', $this->accountStatus('alice@example.com'));

        $again = $this->verify()->handle(Request::create('/verify-email', 'POST', ['token' => $token]), []);
        self::assertSame(Response::HTTP_NOT_FOUND, $again->getStatusCode(), 'single use');
        self::assertSame(Response::HTTP_SEE_OTHER, $this->login('Alice')->getStatusCode());
    }

    public function testExistingAddressGetsTheSameAnswerAndANotice(): void
    {
        $first = $this->register('Alice', 'alice@example.com');
        $second = $this->register('Mallory', 'ALICE@example.com');

        self::assertSame($first->getStatusCode(), $second->getStatusCode());
        self::assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'));
        self::assertCount(2, $this->transport->sent);
        self::assertSame('Someone tried to register with your email address', $this->subject(1));
        self::assertStringContainsString(self::SITE . '/login', $this->transport->sent[1]->toString());

        $third = $this->register('Mallory', 'alice@example.com');
        self::assertSame($first->headers->get('Location'), $third->headers->get('Location'));
        self::assertCount(2, $this->transport->sent, 'notices to one account are limited');
    }

    public function testRefusedTokens(): void
    {
        $this->register('Alice', 'alice@example.com');
        $token = $this->tokenFromMail(0);
        $tampered = substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A');

        self::assertNull($this->registration($this->now)->verify($tampered));
        self::assertNull($this->registration($this->now)->verify('../../etc'));
        self::assertNull($this->registration($this->now->modify('+25 hours'))->verify($token), 'expired');

        $this->connection->executeStatement(
            "UPDATE users SET email = 'new@example.com', email_normalized = 'new@example.com'",
        );
        self::assertNull($this->registration($this->now)->verify($token), 'issued for the old address');

        $this->connection->executeStatement(
            "UPDATE users SET email = 'alice@example.com', email_normalized = 'alice@example.com'",
        );
        self::assertNotNull($this->registration($this->now->modify('+23 hours'))->verify($token));

        $invalid = (string) $this->verify()->handle(Request::create('/verify-email?token=x%3Cb%3E'), [])->getContent();
        self::assertStringContainsString('This confirmation link is not valid.', $invalid);
        self::assertStringNotContainsString('<b>', $invalid);
    }

    public function testResendIsLimitedAndAnswersAlike(): void
    {
        $this->register('Alice', 'alice@example.com');
        $later = $this->registration($this->now->modify('+10 minutes'));

        self::assertSame(0, $later->resend('alice@example.com', '192.0.2.1'));
        self::assertCount(2, $this->transport->sent);
        self::assertGreaterThan(0, $later->resend('alice@example.com', '192.0.2.1'), 'per network');
        self::assertSame(0, $later->resend('alice@example.com', '198.51.100.1'));
        self::assertCount(2, $this->transport->sent, 'per account, silently');
        self::assertSame(0, $this->registration($this->now)->resend('nobody@example.com', '203.0.113.1'));

        $handler = $this->resend();
        $first = $handler->handle(Request::create('/verify-email/resend', 'POST', [
            'email' => 'nobody@example.com',
        ], server: ['REMOTE_ADDR' => '203.0.113.9']), []);
        $limited = $handler->handle(Request::create('/verify-email/resend', 'POST', [
            'email' => 'alice@example.com',
        ], server: ['REMOTE_ADDR' => '203.0.113.9']), []);

        self::assertSame(Response::HTTP_SEE_OTHER, $first->getStatusCode());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $limited->getStatusCode());
        self::assertNotNull($limited->headers->get('Retry-After'));

        $token = $this->tokenFromMail(1);
        self::assertNotNull($this->registration($this->now)->verify($token), 'the new link works');
        self::assertNull($this->registration($this->now)->verify($this->tokenFromMail(0)), 'the old one is spent');
    }

    public function testFormErrorsBansAndMissingSiteAddress(): void
    {
        $invalid = $this->register('x', 'not-an-address', 'short', 'other');
        $content = (string) $invalid->getContent();

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $invalid->getStatusCode());
        self::assertStringContainsString('Choose a user name of 3 to 32', $content);
        self::assertStringContainsString('Enter a valid email address.', $content);
        self::assertStringContainsString('at least 8 characters', $content);
        self::assertStringContainsString('The two passwords are not the same.', $content);
        self::assertStringNotContainsString('short', $content, 'passwords are not sent back');

        $this->register('Alice', 'alice@example.com');
        $taken = $this->register('ALICE', 'other@example.com');
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $taken->getStatusCode());
        self::assertStringContainsString('This user name is already taken.', (string) $taken->getContent());

        $admin = (int) $this->connection->fetchOne('SELECT id FROM users');
        (new DbalBanList($this->connection, new Outbox($this->connection)))
            ->add(BanEntry::parse(BanEntry::EMAIL_DOMAIN, 'spam.example'), 'spam', null, $admin, $this->now);
        $banned = $this->register('Spammer', 'bot@spam.example');
        self::assertSame('/register?sent=1', $banned->headers->get('Location'), 'same answer');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'));
        self::assertCount(1, $this->transport->sent);

        $unset = $this->registerHandler(null)->handle($this->form('Bob', 'bob@example.com'), []);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $unset->getStatusCode());
        self::assertStringContainsString('Mail is not set up on this forum yet.', (string) $unset->getContent());
    }

    public function testBotsAreTurnedAwayBeforeAnyAccountWork(): void
    {
        $rules = new RegistrationRules(networkSeconds: 0);
        $honeypot = $this->submitAfter(new ArraySession(), 10, 'bot1@example.com', $rules, null, ['website' => 'x']);

        self::assertSame('/register?sent=1', $honeypot->headers->get('Location'), 'looks like a success');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'));
        self::assertSame([], $this->transport->sent);

        $fast = $this->submitAfter(new ArraySession(), 1, 'quick@example.com', $rules);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $fast->getStatusCode());
        self::assertStringContainsString('Please take a moment', (string) $fast->getContent());

        $direct = $this->registerHandler(self::SITE, new ArraySession(), $rules)
            ->handle($this->form('Direct', 'direct@example.com'), []);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $direct->getStatusCode(), 'form never shown');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'));

        $human = $this->submitAfter(new ArraySession(), 3, 'human@example.com', $rules);
        self::assertSame(Response::HTTP_SEE_OTHER, $human->getStatusCode());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'));

        $form = (string) $this->registerHandler(self::SITE, new ArraySession(), $rules)
            ->handle(Request::create('/register'), [])->getContent();
        self::assertStringContainsString('name="website" type="text" tabindex="-1"', $form);
        self::assertStringContainsString('class="register-extra" aria-hidden="true"', $form);
    }

    public function testTheBuiltInQuestionIsAnsweredOnce(): void
    {
        $rules = new RegistrationRules(networkSeconds: 0, challenge: RegistrationRules::CHALLENGE_QUESTION);
        $session = new ArraySession();
        $question = new QuestionChallenge($session, static fn(int $min, int $max): int => 4);
        $page = (string) $this->registerHandler(self::SITE, $session, $rules, $question)
            ->handle(Request::create('/register'), [])->getContent();

        self::assertStringContainsString('what is 4 plus 4?', $page);
        self::assertStringNotContainsString('value="8"', $page, 'the answer is not in the page');

        $wrong = $this->submitAfter($session, 5, 'wrong@example.com', $rules, $question, ['challenge' => '7']);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $wrong->getStatusCode());
        self::assertStringContainsString('The answer to the question was not right.', (string) $wrong->getContent());

        $right = $this->submitAfter($session, 5, 'right@example.com', $rules, $question, ['challenge' => '8']);
        self::assertSame(Response::HTTP_SEE_OTHER, $right->getStatusCode());

        self::assertTrue($question->present() !== null);
        self::assertTrue($question->passes(['challenge' => '8']));
        self::assertFalse($question->passes(['challenge' => '8']), 'the same answer cannot be replayed');
    }

    public function testProofOfWorkRendersItsSaltAndAcceptsASolvedNonce(): void
    {
        $rules = new RegistrationRules(networkSeconds: 0, challenge: RegistrationRules::CHALLENGE_WORK);
        $session = new ArraySession();
        $work = new WorkChallenge(
            $session,
            new QuestionChallenge($session, static fn(int $min, int $max): int => 4),
            static fn(): string => 'salt-for-test',
        );
        $page = (string) $this->registerHandler(self::SITE, $session, $rules, $work)
            ->handle(Request::create('/register'), [])->getContent();

        self::assertStringContainsString('data-work-salt="salt-for-test"', $page);
        self::assertStringContainsString('name="work_nonce"', $page);
        self::assertStringContainsString('data-work-fallback', $page, 'the sum stays for browsers without JavaScript');
        self::assertStringContainsString('challenge.js', $page);

        for ($nonce = 0;; $nonce++) {
            $digest = hash('sha256', 'salt-for-test' . $nonce, true);

            if (WorkChallenge::leadingZeroBits($digest) >= WorkChallenge::DIFFICULTY) {
                break;
            }
        }

        $right = $this->submitAfter($session, 5, 'work@example.com', $rules, $work, ['work_nonce' => (string) $nonce]);
        self::assertSame(Response::HTTP_SEE_OTHER, $right->getStatusCode());
    }

    public function testRegistrationsArePacedPerNetwork(): void
    {
        $rules = new RegistrationRules(networkSeconds: 120);
        $first = $this->submitAfter(new ArraySession(), 5, 'one@example.com', $rules, null, [], '2001:db8:1:2::10');
        $second = $this->submitAfter(new ArraySession(), 5, 'two@example.com', $rules, null, [], '2001:db8:1:2::99');
        $other = $this->submitAfter(new ArraySession(), 5, 'three@example.com', $rules, null, [], '198.51.100.7');

        self::assertSame(Response::HTTP_SEE_OTHER, $first->getStatusCode());
        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $second->getStatusCode(), 'same /64');
        self::assertNotNull($second->headers->get('Retry-After'));
        self::assertStringContainsString('Too many accounts were created', (string) $second->getContent());
        self::assertSame(Response::HTTP_SEE_OTHER, $other->getStatusCode());
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM users'));
    }

    public function testOpenApprovalAndClosedModes(): void
    {
        $open = new RegistrationRules(RegistrationRules::OPEN, networkSeconds: 0);
        $this->submitAfter(new ArraySession(), 5, 'olivia@example.com', $open);
        self::assertSame('active', $this->accountStatus('olivia@example.com'));
        self::assertSame([], $this->transport->sent, 'no verification mail in open mode');
        self::assertStringContainsString(
            'your account is ready',
            (string) $this->registerHandler(self::SITE, null, $open)->handle(Request::create('/register?sent=1'), [])
                ->getContent(),
        );

        $approval = new RegistrationRules(RegistrationRules::APPROVAL, networkSeconds: 0);
        $this->submitAfter(new ArraySession(), 5, 'paula@example.com', $approval);
        self::assertSame('pending_verification', $this->accountStatus('paula@example.com'));
        $userId = $this->registration($this->now, self::SITE, $approval)->verify($this->tokenFromMail(0));
        self::assertNotNull($userId);
        self::assertSame('pending_approval', $this->accountStatus('paula@example.com'));
        self::assertStringContainsString(
            'waiting for approval',
            (string) $this->login('paula')->getContent(),
        );

        $closed = new RegistrationRules(RegistrationRules::CLOSED, networkSeconds: 0);
        $page = $this->registerHandler(self::SITE, null, $closed)->handle(Request::create('/register'), []);
        $post = $this->registerHandler(self::SITE, null, $closed)->handle($this->form('Carl', 'carl@example.com'), []);
        self::assertSame(Response::HTTP_FORBIDDEN, $page->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $post->getStatusCode());
        self::assertStringContainsString('does not accept new accounts', (string) $post->getContent());
        self::assertSame('', $this->accountStatus('carl@example.com'));

        try {
            $this->registration($this->now, self::SITE, $closed)
                ->register('Carl', 'carl@example.com', self::PASSWORD, self::PASSWORD, null);
            self::fail('closed');
        } catch (RegistrationRejected $rejected) {
            self::assertSame(['registration_closed'], $rejected->diagnostics);
        }
    }

    public function testAdminPageDisclosesTheSettings(): void
    {
        $rules = new RegistrationRules(
            RegistrationRules::APPROVAL,
            5,
            300,
            RegistrationRules::CHALLENGE_QUESTION,
        );
        $page = (string) (new RegistrationSettingsHandler(self::translator(), self::views(), $rules, self::router()))
            ->handle(Request::create('/admin/registration'), [])->getContent();

        self::assertStringContainsString('Approval: after confirming their address', $page);
        self::assertStringContainsString('5 seconds', $page);
        self::assertStringContainsString('One new account every 300 seconds', $page);
        self::assertStringContainsString('A small sum, built in (no outside service)', $page);
        self::assertStringContainsString('registration sends no data to other companies', $page);
    }

    /**
     * Benchmark case `registration_submit`: a new address (account, membership, token, mail) against an address
     * that has an account (notice, limited after the first). Both hash the password, so the times stay close.
     */
    public function testRegistrationLatencyForNewAndExistingAddresses(): void
    {
        $registration = $this->registration($this->now);
        $tick = 0;
        $new = BenchmarkHarness::measure(static function () use ($registration, &$tick): void {
            ++$tick;
            $email = 'user' . $tick . '@example.com';
            $registration->register('User' . $tick, $email, self::PASSWORD, self::PASSWORD, null);
        }, 20, 2);
        $existing = BenchmarkHarness::measure(static function () use ($registration): void {
            $registration->register('Other', 'user1@example.com', self::PASSWORD, self::PASSWORD, null);
        }, 20, 2);

        fwrite(STDERR, sprintf(
            "registration_submit new p50 %.2f ms  p95 %.2f ms  existing p50 %.2f ms  p95 %.2f ms  peak %d MiB\n",
            $new['p50_ms'],
            $new['p95_ms'],
            $existing['p50_ms'],
            $existing['p95_ms'],
            $new['max_peak_bytes'] / 1048576,
        ));
        self::assertLessThan(500.0, $new['p95_ms']);
        self::assertLessThan(3.0, $new['p50_ms'] / max(0.001, $existing['p50_ms']), 'hashing dominates both paths');
    }

    private function register(
        string $username,
        string $email,
        string $password = self::PASSWORD,
        ?string $confirmation = null,
    ): Response {
        return $this->registerHandler(self::SITE)->handle(
            $this->form($username, $email, $password, $confirmation ?? $password),
            [],
        );
    }

    private function form(
        string $username,
        string $email,
        string $password = self::PASSWORD,
        ?string $confirmation = null,
    ): Request {
        return Request::create('/register', 'POST', [
            'username' => $username,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ], server: ['REMOTE_ADDR' => '192.0.2.50']);
    }

    private function tokenFromMail(int $index): string
    {
        $raw = quoted_printable_decode($this->transport->sent[$index]->toString());
        self::assertSame(
            1,
            preg_match('~' . preg_quote(self::SITE, '~') . '/verify-email\?token=([A-Za-z0-9_-]{43})~', $raw, $match),
        );

        return $match[1];
    }

    private function subject(int $index): string
    {
        $email = $this->transport->sent[$index]->getOriginalMessage();

        return method_exists($email, 'getSubject') ? (string) $email->getSubject() : '';
    }

    private function accountStatus(string $email): string
    {
        return (string) $this->connection->fetchOne('SELECT status FROM users WHERE email_normalized = ?', [$email]);
    }

    private function registration(
        DateTimeImmutable $now,
        ?string $site = self::SITE,
        RegistrationRules $rules = new RegistrationRules(networkSeconds: 0),
    ): AccountRegistration {
        $settings = new MailSettings(fromAddress: 'forum@example.com');
        $mailer = new SymfonyMailer($this->transport, $settings, new NullLogger());
        $composer = new MailComposer(self::views(), self::translator());

        return new AccountRegistration(
            $this->connection,
            new DbalUserRepository($this->connection),
            new DbalUsergroupRepository($this->connection),
            new PasswordHasher(),
            new DbalVerificationTokens($this->connection),
            new TemplatedAccountMail($mailer, $composer, self::router(), $site),
            new RateBuckets($this->connection),
            new DbalBanList($this->connection, new Outbox($this->connection)),
            static fn(): DateTimeImmutable => $now,
            $rules,
        );
    }

    private function registerHandler(
        ?string $site,
        ?ArraySession $form = null,
        RegistrationRules $rules = new RegistrationRules(networkSeconds: 0),
        ?RegistrationChallenge $challenge = null,
        int $time = 1_000_000,
    ): RegisterHandler {
        $session = $form ?? new ArraySession();

        return new RegisterHandler(
            self::translator(),
            self::views(),
            new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
            $this->registration($this->now, $site, $rules),
            new CsrfTokens($session),
            self::router(),
            $form,
            $rules,
            $challenge ?? new NoChallenge(),
            static fn(): int => $time,
        );
    }

    /**
     * Shows the form in `$session` at `$shownAt` and sends it `$after` seconds later (M02.03a).
     *
     * @param array<string, string> $extra
     */
    private function submitAfter(
        ArraySession $session,
        int $after,
        string $email,
        RegistrationRules $rules,
        ?RegistrationChallenge $challenge = null,
        array $extra = [],
        string $ip = '192.0.2.50',
    ): Response {
        $shownAt = 1_000_000;
        $this->registerHandler(self::SITE, $session, $rules, $challenge, $shownAt)
            ->handle(Request::create('/register'), []);

        return $this->registerHandler(self::SITE, $session, $rules, $challenge, $shownAt + $after)->handle(
            Request::create('/register', 'POST', [
                'username' => strstr($email, '@', true),
                'email' => $email,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ] + $extra, server: ['REMOTE_ADDR' => $ip]),
            [],
        );
    }

    private function verify(): VerifyEmailHandler
    {
        $session = new ArraySession();

        return new VerifyEmailHandler(
            self::translator(),
            self::views(),
            $this->registration($this->now),
            new CsrfTokens($session),
            self::router(),
            false,
        );
    }

    private function resend(): VerifyEmailHandler
    {
        $session = new ArraySession();

        return new VerifyEmailHandler(
            self::translator(),
            self::views(),
            $this->registration($this->now->modify('+1 hour')),
            new CsrfTokens($session),
            self::router(),
            true,
        );
    }

    private function login(string $identifier): Response
    {
        $session = new ArraySession();

        return (new LoginHandler(
            self::translator(),
            self::views(),
            new Authenticator(new DbalUserRepository($this->connection), new PasswordHasher()),
            new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
            new CsrfTokens($session),
            self::router(),
            new LoginThrottle(new DbalLoginAttempts($this->connection), random: static fn(): int => 2),
        ))->handle(Request::create('/login', 'POST', ['identifier' => $identifier, 'password' => self::PASSWORD]), []);
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
