<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Installer;

use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Http\InstallerMailHandler;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\TransportPolicy;
use Nolviko\Core\Installer\MailSetup;
use Nolviko\Core\Installer\SetupCode;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Mail\MailComposer;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/** Installer form authorization and secret handling without contacting any real mail server. */
final class InstallerMailStepTest extends TestCase
{
    private TemporaryDirectory $site;
    private ArraySession $session;
    private CsrfTokens $csrf;
    private InstallerMailHandler $handler;

    protected function setUp(): void
    {
        $this->site = new TemporaryDirectory();
        $this->site->write('public/.keep', '');
        $this->site->write('storage/.keep', '');
        $this->site->write('config/local.php', '<?php return ' . var_export([
            'database' => ['driver' => 'mysql', 'host' => 'localhost', 'name' => 'fixture', 'user' => 'fixture',
                'password' => 'fixture-db-secret'],
            'app' => ['secret' => str_repeat('a', 64)],
        ], true) . ';');
        $this->session = new ArraySession();
        $this->csrf = new CsrfTokens($this->session);
        $this->handler = $this->handler(InstallationState::Configured);
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testCsrfAndOwnerProofAreBothRequired(): void
    {
        $before = file_get_contents($this->site->path . '/config/local.php');
        self::assertSame(403, $this->post(['action' => 'skip', CsrfTokens::FIELD => 'invalid'])->getStatusCode());
        self::assertSame(403, $this->post(['action' => 'skip', 'setup_code' => 'wrong'])->getStatusCode());
        self::assertSame($before, file_get_contents($this->site->path . '/config/local.php'));
    }

    public function testNativeSkipAndSenderFallbackPreserveExistingConfiguration(): void
    {
        $response = $this->post(['action' => 'skip', 'smtp_password' => 'discard-this-secret']);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/install/admin', $response->headers->get('Location'));
        $setup = new MailSetup($this->site->path . '/config', $this->site->path . '/public');
        $setup->ensureSender('admin@example.com');
        $setup->ensureSender('ignored@example.com');
        $config = require $this->site->path . '/config/local.php';
        self::assertSame('admin@example.com', $config['mail']['from_address']);
        self::assertArrayNotHasKey('smtp_password', $config['mail']);
        self::assertSame('fixture-db-secret', $config['database']['password']);
    }

    public function testSmtpIsRefusedOnHttpAndItsPasswordIsNeverRedisplayed(): void
    {
        $before = file_get_contents($this->site->path . '/config/local.php');
        $response = $this->post(
            ['action' => 'save', 'transport' => 'smtp', 'smtp_password' => 'unique-smtp-secret'],
            false,
        );
        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('unique-smtp-secret', (string) $response->getContent());
        self::assertSame($before, file_get_contents($this->site->path . '/config/local.php'));
    }

    public function testSecureSmtpSaveDoesNotSendAndPersistsOnlyPrivateConfig(): void
    {
        $response = $this->post(['action' => 'save', 'transport' => 'smtp', 'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587', 'smtp_encryption' => 'starttls', 'from_address' => 'forum@example.com',
            'smtp_password' => 'fixture-smtp-secret']);
        self::assertSame(303, $response->getStatusCode());
        $config = require $this->site->path . '/config/local.php';
        self::assertSame('fixture-smtp-secret', $config['mail']['smtp_password']);
        self::assertSame('fixture-db-secret', $config['database']['password']);
    }

    public function testTestSendValidatesRecipientAndCooldownWithoutSaving(): void
    {
        $before = file_get_contents($this->site->path . '/config/local.php');
        self::assertSame(422, $this->post(['action' => 'test', 'recipient' => 'invalid'])->getStatusCode());
        $this->session->set('_installer_mail_test_at', time());
        $response = $this->post(['action' => 'test', 'recipient' => 'admin@example.com']);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('30', $response->headers->get('Retry-After'));
        self::assertSame($before, file_get_contents($this->site->path . '/config/local.php'));
    }

    public function testInstalledSiteCannotReopenTheMailStep(): void
    {
        $this->expectException(ResourceNotFoundException::class);
        $this->handler(InstallationState::Installed)->handle(Request::create('/install/mail'), []);
    }

    private function handler(InstallationState $state): InstallerMailHandler
    {
        $app = dirname(__DIR__, 3);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $views = TwigFactory::create($app, $this->site->path . '/storage', $translator);
        $code = new SetupCode($this->site->path . '/storage', $this->session);
        self::assertTrue($code->ensure());

        return new InstallerMailHandler(
            new InstallerPage($translator, $views),
            $state,
            $code,
            new MailSetup($this->site->path . '/config', $this->site->path . '/public'),
            new MailComposer($views, $translator),
            new NullLogger(),
            $this->session,
            $this->csrf,
            new Router(CompiledRoutes::compile(CoreRoutes::definitions())),
        );
    }

    /** @param array<string, string> $fields */
    private function post(array $fields, bool $secure = true): Response
    {
        $fields += [CsrfTokens::FIELD => $this->csrf->token(),
            'setup_code' => trim(file_get_contents($this->site->path . '/storage/' . SetupCode::FILE))];

        $request = Request::create(($secure ? 'https' : 'http') . '://forum.test/install/mail', 'POST', $fields);
        // Plain HTTP is refused only where the board requires HTTPS (HttpsGuard; optional since 2026-11-09).
        $request->attributes->set(TransportPolicy::ATTRIBUTE, $secure);

        return $this->handler->handle($request, []);
    }
}
