<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\MailSettings;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
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
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Http\MailSettingsHandler;
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
 * M02.02a on MySQL 8.4: the admin mail page shows the transport without secrets, warns about PHP `mail()`, and
 * sends a test mail synchronously to the administrator's own address only after a fresh password confirmation;
 * a transport failure is answered 503 with the translated "could not send" message.
 */
final class AdminMailTest extends TestCase
{
    private Connection $connection;

    private int $admin;

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
        $this->admin = $users->create('Admin', 'admin@example.com', 'h', UserStatus::Active)->id;
        (new DbalUsergroupRepository($this->connection))->addMember($this->admin, BuiltInGroup::Administrators->value);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testTestMailNeedsAFreshConfirmationAndGoesToTheAdmin(): void
    {
        $session = $this->session();
        $transport = new CapturingTransport();
        $settings = new MailSettings(fromAddress: 'forum@example.com');
        $page = (string) $this->handler($session, $transport, $settings)->handle(Request::create('/admin/mail'), [])
            ->getContent();

        self::assertStringContainsString('PHP mail() function', $page);
        self::assertStringContainsString('spam folder', $page, 'mail() is flagged');

        (new AdminSession($session))->confirm();
        $sent = $this->handler($session, $transport, $settings)->handle(Request::create('/admin/mail', 'POST', [
            'to' => 'someone-else@example.com',
        ]), []);

        self::assertSame(Response::HTTP_SEE_OTHER, $sent->getStatusCode());
        self::assertSame('/admin/mail?sent=1', $sent->headers->get('Location'));
        self::assertCount(1, $transport->sent);
        self::assertSame(
            ['admin@example.com'],
            array_map(static fn($address): string => $address->getAddress(), $transport->sent[0]->getEnvelope()
                ->getRecipients()),
            'the recipient cannot be chosen in the request',
        );
        self::assertStringContainsString('Hello Admin,', $transport->sent[0]->toString());
    }

    public function testFailuresShowTheTranslatedMessageAndNoSecret(): void
    {
        $session = $this->session();
        (new AdminSession($session))->confirm();
        $settings = new MailSettings(
            MailSettings::SMTP,
            'forum@example.com',
            smtpHost: 'smtp.example.com',
            smtpUser: 'forum',
            smtpPassword: new Secret('top-secret-smtp-password'),
        );
        $failed = $this->handler($session, new CapturingTransport('connection refused'), $settings)
            ->handle(Request::create('/admin/mail', 'POST'), []);
        $content = (string) $failed->getContent();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $failed->getStatusCode());
        self::assertStringContainsString('The mail could not be sent. Please try again in a few minutes.', $content);
        self::assertStringContainsString('smtp.example.com on port 587', $content);
        self::assertStringNotContainsString('top-secret-smtp-password', $content);
        self::assertStringNotContainsString('connection refused', $content);

        $unset = $this->handler($session, new CapturingTransport(), new MailSettings())
            ->handle(Request::create('/admin/mail', 'POST'), []);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $unset->getStatusCode());
        self::assertStringContainsString('Mail is not set up on this forum yet.', (string) $unset->getContent());
    }

    /**
     * Benchmark case `mail_compose_send`: compose the translated text+HTML test mail and hand it to an in-memory
     * transport (MIME build included, no network).
     */
    public function testComposeAndSendLatency(): void
    {
        $transport = new CapturingTransport();
        $mailer = new SymfonyMailer($transport, new MailSettings(fromAddress: 'forum@example.com'), new NullLogger());
        $composer = new MailComposer(self::views(), self::translator());
        $result = BenchmarkHarness::measure(static function () use ($mailer, $composer): void {
            $mailer->send($composer->compose('test', 'mail.test.subject', 'a@example.com', ['name' => 'A']));
        }, 50, 5);

        fwrite(STDERR, sprintf(
            "mail_compose_send p50 %.4f ms  p95 %.4f ms  peak %d MiB\n",
            $result['p50_ms'],
            $result['p95_ms'],
            $result['max_peak_bytes'] / 1048576,
        ));
        self::assertLessThan(100.0, $result['p95_ms']);
    }

    private function session(): ArraySession
    {
        $session = new ArraySession();
        $session->set('_user_id', $this->admin);

        return $session;
    }

    private function handler(
        ArraySession $session,
        CapturingTransport $transport,
        MailSettings $settings,
    ): MailSettingsHandler {
        $catalog = PermissionCatalog::core();

        return new MailSettingsHandler(
            self::translator(),
            self::views(),
            new RequestSubject(
                new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
                new DbalUsergroupRepository($this->connection),
                new PermissionResolver(
                    $catalog,
                    new DbalPermissionRuleStore($this->connection, $catalog),
                    new DbalForumTree($this->connection),
                ),
            ),
            new DbalUserRepository($this->connection),
            $settings,
            new SymfonyMailer($transport, $settings, new NullLogger()),
            new MailComposer(self::views(), self::translator()),
            new AdminSession($session),
            new CsrfTokens($session),
            new Router(CompiledRoutes::compile(CoreRoutes::definitions())),
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
}
