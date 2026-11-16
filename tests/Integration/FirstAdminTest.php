<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\Migration\MigrationRegistry;
use Nolviko\Core\Database\Migration\SchemaInstaller;
use Nolviko\Core\Http\InstallationGuard;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Installer\SetupCode;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Users\Domain\FirstAdminRejected;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\InstallerAdminHandler;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Modules\Users\Infrastructure\FirstAdminProvisioner;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * M02.15a on MySQL 8.4: the installer creates the first administrator only with the setup code and the password
 * policy, records it in the audit log and writes the install lock; repeating cannot create a second
 * administrator, a failure part-way rolls the account back, a lock that could not be written is finished on the
 * next attempt, and after the lock the installer is gone.
 */
final class FirstAdminTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private Connection $connection;

    private TemporaryDirectory $storage;

    private TemporaryDirectory $config;

    private ArraySession $session;

    protected function setUp(): void
    {
        if ((getenv('FORUM_TEST_DB_HOST') ?: '') === '') {
            self::markTestSkipped('FORUM_TEST_DB_HOST not set');
        }

        $this->connection = self::connect();
        if (!TestDatabase::isDisposable($this->connection)) {
            self::fail('the test database holds an installed forum; refusing to drop its tables');
        }

        TestDatabase::dropAll($this->connection);
        $this->storage = new TemporaryDirectory();
        $this->config = new TemporaryDirectory();
        file_put_contents($this->config->path . '/' . InstallationState::CONFIG_FILE, "<?php\nreturn [];\n");
        $this->session = new ArraySession();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::dropAll($this->connection);
        }
    }

    public function testTheStepWaitsForTheSchema(): void
    {
        $response = $this->handler()->handle(Request::create('/install/admin'), []);

        self::assertSame([Response::HTTP_SEE_OTHER, '/install/migrate'], [
            $response->getStatusCode(),
            $response->headers->get('Location'),
        ]);
    }

    public function testTheFirstAdministratorIsCreatedOnceAndTheInstallerCloses(): void
    {
        $this->migrate();
        $form = $this->handler()->handle(Request::create('/install/admin'), []);
        $code = $this->code();
        $wrong = $this->post(['setup_code' => 'AAAAA-AAAAA-AAAAA-AAAAA'] + $this->fields());

        self::assertSame(Response::HTTP_OK, $form->getStatusCode());
        self::assertStringContainsString('autocomplete="new-password"', (string) $form->getContent());
        self::assertSame(422, $wrong->getStatusCode());
        self::assertSame(0, $this->rows('users'), 'nothing is created without the setup code');

        $done = $this->post(['setup_code' => $code] + $this->fields());
        self::assertSame(Response::HTTP_SEE_OTHER, $done->getStatusCode());
        self::assertSame('/login', $done->headers->get('Location'));
        self::assertSame(InstallationState::Installed, InstallationState::detect($this->config->path));
        self::assertFileDoesNotExist($this->storage->path . '/' . SetupCode::FILE, 'the setup code is gone');

        $admin = (new DbalUserRepository($this->connection))->findById(1);
        self::assertNotNull($admin);
        self::assertSame(['Admin', UserStatus::Active], [$admin->username, $admin->status]);
        self::assertTrue((new PasswordHasher())->verify(self::PASSWORD, $admin->passwordHash));
        self::assertSame(
            [BuiltInGroup::Administrators->value, BuiltInGroup::Registered->value],
            (new DbalUsergroupRepository($this->connection))->groupIdsOf($admin->id),
            'administrators is the primary group',
        );
        $audit = $this->connection->fetchAssociative('SELECT action, actor_id, target_id FROM audit_logs');
        self::assertIsArray($audit);
        self::assertSame(
            [FirstAdminProvisioner::AUDIT_ACTION, $admin->id, $admin->id],
            [$audit['action'], (int) $audit['actor_id'], (int) $audit['target_id']],
        );

        $this->expectException(ResourceNotFoundException::class);
        (new InstallationGuard(InstallationState::Installed, self::router()))->guard(
            ['_route' => CoreRoutes::INSTALLER_ADMIN],
            Request::create('/install/admin', 'POST'),
        );
    }

    public function testInvalidInputAndRepeatsAreRefused(): void
    {
        $this->migrate();
        $provisioner = $this->provisioner();

        try {
            $provisioner->provision('ab', 'nope', 'password', 'other');
            self::fail('accepted');
        } catch (FirstAdminRejected $rejected) {
            self::assertSame(
                ['username_invalid', 'email_invalid', 'password_too_common', 'password_mismatch'],
                $rejected->diagnostics,
            );
        }

        (new DbalUserRepository($this->connection))->create('Admin', 'other@example.com', 'h', UserStatus::Active);
        self::assertSame(['identity_taken:username'], $this->rejection($provisioner, 'Admin'));

        $provisioner->provision('Root', 'root@example.com', self::PASSWORD, self::PASSWORD);
        self::assertSame(['installer_admin_exists'], $this->rejection($provisioner, 'Second'));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM usergroup_members WHERE usergroup_id = ?',
            [BuiltInGroup::Administrators->value],
        ));
    }

    public function testAFailurePartWayRollsTheAccountBack(): void
    {
        $this->migrate();
        $this->connection->executeStatement('ALTER TABLE audit_logs RENAME TO audit_logs_moved');

        try {
            $this->provisioner()->provision('Admin', 'admin@example.com', self::PASSWORD, self::PASSWORD);
            self::fail('provisioning ignored the failure');
        } catch (TableNotFoundException) {
        } finally {
            $this->connection->executeStatement('ALTER TABLE audit_logs_moved RENAME TO audit_logs');
        }

        self::assertSame([0, 0], [$this->rows('users'), $this->rows('usergroup_members')]);
        $retry = $this->provisioner()->provision('Admin', 'admin@example.com', self::PASSWORD, self::PASSWORD);

        self::assertGreaterThan(0, $retry, 'the step can be repeated');
        self::assertSame(1, $this->rows('users'));
    }

    public function testALockThatCouldNotBeWrittenIsFinishedNextTime(): void
    {
        $this->migrate();
        $code = $this->code();
        $failed = $this->handler($this->config->path . '/missing')->handle(
            Request::create('/install/admin', 'POST', ['setup_code' => $code] + $this->fields()),
            [],
        );

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $failed->getStatusCode());
        self::assertStringContainsString('config/install.lock could not be written', (string) $failed->getContent());
        self::assertSame(1, $this->rows('users'), 'the account was committed');

        $resume = $this->handler()->handle(Request::create('/install/admin'), []);
        $finished = $this->post([]);

        self::assertStringContainsString('The administrator account exists already.', (string) $resume->getContent());
        self::assertSame(Response::HTTP_SEE_OTHER, $finished->getStatusCode());
        self::assertSame(1, $this->rows('users'), 'no second account');
        self::assertSame(InstallationState::Installed, InstallationState::detect($this->config->path));
    }

    /**
     * @return list<string>
     */
    private function rejection(FirstAdminProvisioner $provisioner, string $username): array
    {
        try {
            $provisioner->provision($username, strtolower($username) . '@example.com', self::PASSWORD, self::PASSWORD);
        } catch (FirstAdminRejected $rejected) {
            return $rejected->diagnostics;
        }

        self::fail('accepted');
    }

    /**
     * @return array<string, string>
     */
    private function fields(): array
    {
        return [
            'username' => 'Admin',
            'email' => 'admin@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ];
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(array $fields): Response
    {
        return $this->handler()->handle(Request::create('/install/admin', 'POST', $fields), []);
    }

    private function code(): string
    {
        $setup = new SetupCode($this->storage->path, $this->session);
        $setup->ensure();

        return trim((string) file_get_contents($setup->path()));
    }

    private function migrate(): void
    {
        $installer = new SchemaInstaller(static fn(): Connection => self::connect(), MigrationRegistry::core());

        while (!$installer->run()['complete']) {
            // One batch per call, like the installer page.
        }
    }

    private function rows(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function provisioner(): FirstAdminProvisioner
    {
        return new FirstAdminProvisioner(
            $this->connection,
            new DbalUserRepository($this->connection),
            new DbalUsergroupRepository($this->connection),
            new PasswordHasher(),
        );
    }

    private function handler(?string $configDirectory = null): InstallerAdminHandler
    {
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $installer = new SchemaInstaller(static fn(): Connection => self::connect(), MigrationRegistry::core());

        return new InstallerAdminHandler(
            new InstallerPage(
                $translator,
                TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-no-storage', $translator),
            ),
            InstallationState::Configured,
            new SetupCode($this->storage->path, $this->session),
            static fn(): SchemaInstaller => $installer,
            fn(): FirstAdminProvisioner => $this->provisioner(),
            new CsrfTokens($this->session),
            self::router(),
            $configDirectory ?? $this->config->path,
        );
    }

    private static function router(): Router
    {
        return new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
    }

    private static function connect(): Connection
    {
        return ConnectionFactory::create(new Config(
            TestDatabase::driver(),
            (string) getenv('FORUM_TEST_DB_HOST'),
            null,
            (string) getenv('FORUM_TEST_DB_NAME'),
            (string) getenv('FORUM_TEST_DB_USER'),
            new Secret((string) getenv('FORUM_TEST_DB_PASSWORD')),
            new Secret(str_repeat('s', 32)),
        ));
    }
}
