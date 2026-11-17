<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\MailSettings;
use Nolviko\Core\Config\RegistrationRules;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Settings\SiteSettings;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Http\SiteSettingsHandler;
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
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** ADR-034: stored settings, validation, cache invalidation, config precedence and the admin screen. */
final class SiteSettingsTest extends TestCase
{
    private Connection $connection;

    private TemporaryDirectory $storage;

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
        $this->storage = new TemporaryDirectory();
        $this->admin = (new DbalUserRepository($this->connection))
            ->create('Admin', 'admin@example.com', 'h', UserStatus::Active)->id;
        (new DbalUsergroupRepository($this->connection))
            ->addMember($this->admin, BuiltInGroup::Administrators->value, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
        if (isset($this->storage)) {
            $this->storage->remove();
        }
    }

    public function testSaveValidatesCachesAndAudits(): void
    {
        $settings = $this->settings();
        self::assertSame([], $settings->values());

        self::assertSame(3, $settings->save([
            'site.name' => '  Çay Ocağı  ',
            'registration.mode' => 'approval',
            'mail.from_address' => 'forum@example.org',
            'mail.from_name' => '',
        ], [], $this->admin));
        self::assertSame('Çay Ocağı', $this->settings()->get('site.name'), 'a fresh store reads the saved value');
        self::assertSame('approval', $this->settings()->get('registration.mode'));
        self::assertSame(0, $settings->save(['site.name' => 'Çay Ocağı'], [], $this->admin), 'unchanged');
        self::assertSame(1, $settings->save(['site.name' => ''], [], $this->admin));
        self::assertNull($this->settings()->get('site.name'), 'an empty value removes the row');
        self::assertSame(0, $settings->save(['mail.from_name' => 'Pinned'], ['mail.from_name'], $this->admin));

        foreach (
            [['registration.mode' => 'anyone'], ['registration.min_seconds' => '61'],
            ['mail.from_address' => 'not-an-address'], ['site.name' => str_repeat('a', 81)],
            ['site.description' => "line\nbreak"], ['database.password' => 'x']] as $invalid
        ) {
            try {
                $settings->save($invalid, [], $this->admin);
                self::fail('accepted ' . json_encode($invalid));
            } catch (InvalidArgumentException $failure) {
                self::assertStringStartsWith('setting_invalid:', $failure->getMessage());
            }
        }

        self::assertSame(2, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.settings_changed'",
        ));
        self::assertSame('approval', $this->settings()->peek('registration.mode'), 'peek reads the cache');
    }

    public function testPageLanguageSurvivesACacheClearWithoutTheDatabase(): void
    {
        $boot = $this->storage->path . '/settings/boot.json';
        $connection = $this->connection;
        $settings = new SiteSettings(
            static fn(): Connection => $connection,
            (new CacheFactory($this->storage->path))->namespace('settings'),
            $boot,
        );
        $settings->save(['locale.default' => 'en', 'site.name' => 'Forum'], [], $this->admin);
        self::assertSame(['locale.default' => 'en'], json_decode((string) file_get_contents($boot), true));

        // An update or a cache reset empties the cache; the language still resolves and no connection is built.
        (new CacheFactory($this->storage->path))->namespace('settings')->clear();
        $offline = new SiteSettings(
            static fn(): Connection => throw new \LogicException('no database for the page language'),
            (new CacheFactory($this->storage->path))->namespace('settings'),
            $boot,
        );
        self::assertSame('en', $offline->cached('locale.default'));
        self::assertNull($offline->cached('site.name'), 'only the boot keys live in the boot file');
    }

    public function testStoredValuesFillOnlyWhatConfigLeavesAtTheDefault(): void
    {
        $settings = $this->settings();
        $settings->save(
            ['registration.mode' => 'closed', 'mail.from_address' => 'stored@example.org'],
            [],
            $this->admin,
        );
        $merge = static fn(RegistrationRules $rules, SiteSettings $site): string
            => $rules->mode === RegistrationRules::VERIFY && $site->get('registration.mode') !== null
                ? (string) $site->get('registration.mode') : $rules->mode;

        self::assertSame('closed', $merge(new RegistrationRules(), $this->settings()));
        self::assertSame('open', $merge(new RegistrationRules(RegistrationRules::OPEN), $this->settings()));
        $mail = new MailSettings(fromAddress: 'config@example.org');
        self::assertSame('config@example.org', $mail->fromAddress ?? $this->settings()->get('mail.from_address'));
    }

    public function testAdminScreenNeedsCsrfAndAFreshSessionAndShowsPinnedFields(): void
    {
        $session = new ArraySession();
        $session->set('_user_id', $this->admin);
        $csrf = new CsrfTokens($session);
        $adminSession = new AdminSession($session);
        $translator = TranslatorFactory::create(dirname(__DIR__, 2) . '/translations', 'en');
        $catalog = PermissionCatalog::core();
        $subjects = new RequestSubject(
            new AuthSession($session, $csrf, new MemoryUsers(), new ResponseCookies()),
            new DbalUsergroupRepository($this->connection),
            new PermissionResolver(
                $catalog,
                new DbalPermissionRuleStore($this->connection, $catalog),
                new DbalForumTree($this->connection),
            ),
        );
        $config = new Config(
            'mysql',
            'h',
            null,
            'd',
            'u',
            new Secret('p'),
            new Secret(str_repeat('s', 32)),
            mail: new MailSettings(fromName: 'From config')
        );
        $handler = new SiteSettingsHandler(
            $this->settings(),
            $config,
            $subjects,
            $adminSession,
            $csrf,
            $session,
            $translator,
            TwigFactory::create(dirname(__DIR__, 2), sys_get_temp_dir() . '/nolviko-no-storage', $translator),
            new Router(CompiledRoutes::compile(CoreRoutes::definitions())),
        );
        $post = ['setting' => ['site.name' => 'Example board', 'mail.from_name' => 'Ignored']];

        $page = (string) $handler->handle(Request::create('/admin/site'), [])->getContent();
        self::assertStringContainsString('name="setting[site.name]"', $page);
        self::assertMatchesRegularExpression('~value="From config" dir="auto" disabled~', $page);
        self::assertSame(403, $handler->handle(Request::create('/admin/site', 'POST', $post), [])->getStatusCode());

        $adminSession->confirm();
        $post[CsrfTokens::FIELD] = $csrf->token();
        self::assertSame(303, $handler->handle(Request::create('/admin/site', 'POST', $post), [])->getStatusCode());
        self::assertSame('Example board', $this->settings()->get('site.name'));
        self::assertNull($this->settings()->get('mail.from_name'), 'a pinned key is never written');
        $post['setting'] = ['registration.min_seconds' => '99'];
        self::assertSame(422, $handler->handle(Request::create('/admin/site', 'POST', $post), [])->getStatusCode());
    }

    private function settings(): SiteSettings
    {
        $connection = $this->connection;

        return new SiteSettings(
            static fn(): Connection => $connection,
            (new CacheFactory($this->storage->path))->namespace('settings'),
        );
    }
}
