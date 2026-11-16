<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Installer;

use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Database\DatabaseHealthException;
use Nolviko\Core\Http\InstallerDatabaseHandler;
use Nolviko\Core\Http\InstallerMigrateHandler;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Installer\DatabaseSetup;
use Nolviko\Core\Installer\SetupCode;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\FakeSchema;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M01.14 installer steps 2 and 3 at handler level (CsrfGuard is covered by Unit\Http\CsrfGuardTest; the real
 * session, CSRF and MySQL path by the Docker end-to-end check).
 */
final class InstallerWizardTest extends TestCase
{
    private const PASSWORD = 'secret-pa55word';

    private TemporaryDirectory $site;

    private ArraySession $session;

    private Router $router;

    private bool $connectionFails = false;

    protected function setUp(): void
    {
        $this->site = new TemporaryDirectory();
        mkdir($this->site->path . '/storage');
        mkdir($this->site->path . '/config');
        mkdir($this->site->path . '/public');
        $this->session = new ArraySession();
        $this->router = new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testFormAsksForTheSetupCodeAndCreatesItsFile(): void
    {
        $response = $this->database(InstallationState::NotConfigured, Request::create('/install/database'));
        $html = (string) $response->getContent();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertFileExists($this->site->path . '/storage/' . SetupCode::FILE);
        self::assertStringContainsString('name="setup_code"', $html);
        self::assertStringContainsString('storage/install/setup-code.txt', $html);
        self::assertStringContainsString('name="' . CsrfTokens::FIELD . '" value="', $html);
        self::assertStringContainsString('<label for="db-password">', $html);
        self::assertStringContainsString('<form method="post" action="/install/database">', $html);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testWrongSetupCodeIsRefusedAndNothingIsWritten(): void
    {
        $this->database(InstallationState::NotConfigured, Request::create('/install/database'));
        $response = $this->database(InstallationState::NotConfigured, $this->post(['setup_code' => 'WRONG']));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString('The setup code is not correct.', (string) $response->getContent());
        self::assertFileDoesNotExist($this->site->path . '/config/local.php');
    }

    public function testFailedConnectionKeepsEnteredValuesButNeverThePassword(): void
    {
        $this->connectionFails = true;
        $this->database(InstallationState::NotConfigured, Request::create('/install/database'));
        $response = $this->database(InstallationState::NotConfigured, $this->post(['setup_code' => $this->code()]));
        $html = (string) $response->getContent();

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertStringContainsString('<div role="alert">', $html);
        self::assertStringContainsString('The database rejected the user name or password.', $html);
        self::assertStringContainsString('value="db.example"', $html);
        self::assertStringNotContainsString(self::PASSWORD, $html);
        self::assertStringNotContainsString('name="setup_code"', $html, 'the proof is kept for the retry');
        self::assertFileDoesNotExist($this->site->path . '/config/local.php');
    }

    public function testHostileValuesAreEscapedInTheForm(): void
    {
        $this->connectionFails = true;
        $this->database(InstallationState::NotConfigured, Request::create('/install/database'));
        $response = $this->database(InstallationState::NotConfigured, $this->post([
            'setup_code' => $this->code(),
            'host' => '"><script>x</script>',
        ]));

        self::assertStringNotContainsString('<script>x', (string) $response->getContent());
        self::assertStringContainsString('&quot;&gt;&lt;script&gt;', (string) $response->getContent());
    }

    public function testValidSettingsAreSavedAndLeadToTheMigrationStep(): void
    {
        $this->database(InstallationState::NotConfigured, Request::create('/install/database'));
        $response = $this->database(InstallationState::NotConfigured, $this->post(['setup_code' => $this->code()]));

        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertSame('/install/migrate', $response->headers->get('Location'));
        self::assertFileExists($this->site->path . '/config/local.php');
        self::assertSame(1, $this->session->regenerations, 'proving the code renews the session ID');
    }

    public function testConfiguredSiteSkipsTheDatabaseStepAndUnconfiguredSiteSkipsMigration(): void
    {
        $database = $this->database(InstallationState::Configured, Request::create('/install/database'));
        $request = Request::create('/install/migrate');
        $migrate = $this->migrate(InstallationState::NotConfigured, $request, new FakeSchema());

        self::assertSame('/install/migrate', $database->headers->get('Location'));
        self::assertSame('/install/database', $migrate->headers->get('Location'));
    }

    public function testMigrationNeedsTheSetupCodeThenRunsAndRedirects(): void
    {
        $schema = new FakeSchema();
        $denied = $this->migrate(InstallationState::Configured, $this->post([], '/install/migrate'), $schema);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $denied->getStatusCode());
        self::assertSame([], $schema->calls, 'nothing ran without the code');

        $proof = $this->post(['setup_code' => $this->code()], '/install/migrate');
        $done = $this->migrate(InstallationState::Configured, $proof, $schema);

        self::assertSame(Response::HTTP_SEE_OTHER, $done->getStatusCode());
        self::assertSame('/install/migrate', $done->headers->get('Location'));
        self::assertSame(['run'], $schema->calls);
    }

    public function testMigrationStatusShowsPendingAndCompleteStates(): void
    {
        $status = Request::create('/install/migrate');
        $pending = (string) $this->migrate(InstallationState::Configured, $status, new FakeSchema(1))->getContent();
        $complete = (string) $this->migrate(InstallationState::Configured, $status, new FakeSchema(0))->getContent();

        self::assertStringContainsString('1 database update is waiting.', $pending);
        self::assertStringContainsString('<button type="submit">', $pending);
        self::assertStringContainsString('The database is ready.', $complete);
        self::assertStringNotContainsString('<form', $complete);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(array $fields, string $path = '/install/database'): Request
    {
        return Request::create($path, 'POST', $fields + [
            'host' => 'db.example',
            'port' => '',
            'name' => 'forum',
            'user' => 'forum',
            'password' => self::PASSWORD,
        ]);
    }

    private function code(): string
    {
        return trim((string) file_get_contents($this->site->path . '/storage/' . SetupCode::FILE));
    }

    private function database(InstallationState $state, Request $request): Response
    {
        $verify = function (): void {
            if ($this->connectionFails) {
                throw new DatabaseHealthException(['database_auth_failed']);
            }
        };
        $handler = new InstallerDatabaseHandler(
            $this->page(),
            $state,
            new SetupCode($this->site->path . '/storage', $this->session),
            new DatabaseSetup($this->site->path . '/config', $this->site->path . '/public', $verify),
            new CsrfTokens($this->session),
            $this->router,
        );

        return $handler->handle($request, []);
    }

    private function migrate(InstallationState $state, Request $request, FakeSchema $schema): Response
    {
        $setupCode = new SetupCode($this->site->path . '/storage', $this->session);
        $setupCode->ensure();
        $handler = new InstallerMigrateHandler(
            $this->page(),
            $state,
            $setupCode,
            static fn(): FakeSchema => $schema,
            new CsrfTokens($this->session),
            $this->router,
        );

        return $handler->handle($request, []);
    }

    private function page(): InstallerPage
    {
        $app = dirname(__DIR__, 3);
        $translator = TranslatorFactory::create($app . '/translations', 'en');

        return new InstallerPage($translator, TwigFactory::create($app, $this->site->path . '/no-cache', $translator));
    }
}
