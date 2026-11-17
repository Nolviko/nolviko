<?php

declare(strict_types=1);

namespace Nolviko\Tests\Integration;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\ExtensionMigrations;
use Nolviko\Core\Extensions\ExtensionBootstrap;
use Nolviko\Core\Extensions\ExtensionRegistry;
use Nolviko\Core\Extensions\ExtensionRouteGuard;
use Nolviko\Core\Http\AppearanceHandler;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Http\ThemeRequest;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Core\View\ThemeArchive;
use Nolviko\Core\View\ThemeBuildStore;
use Nolviko\Core\View\ThemePresentation;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\AdminGuard;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Http\DashboardHandler;
use Nolviko\Modules\Admin\Http\ExtensionsHandler;
use Nolviko\Modules\Admin\Http\ForumModeratorsHandler;
use Nolviko\Modules\Admin\Http\ReauthHandler;
use Nolviko\Modules\Admin\Http\SavedFiltersHandler;
use Nolviko\Modules\Admin\Http\SettingsCatalog;
use Nolviko\Modules\Admin\Http\ThemesHandler;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminStats;
use Nolviko\Modules\Admin\Infrastructure\ForumModeratorAssignments;
use Nolviko\Modules\Admin\Infrastructure\SavedAdminFilters;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionDefinition;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Modules\Users\Infrastructure\DbalLoginAttempts;
use Nolviko\Modules\Users\Infrastructure\DbalThemePreference;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryUsers;
use Nolviko\Tests\Support\TemporaryDirectory;
use Nolviko\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M02.15 (admin guard, re-authentication) and M04.01 (admin shell) on MySQL 8.4: guests go to the login,
 * members get 403, administrators reach a keyboard-usable, JavaScript-free dashboard with success, empty and
 * error states; re-authentication is throttled and audited and opens the 15-minute window.
 */
final class AdminAreaTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private Connection $connection;

    private int $admin;

    private int $member;

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
        $groups = new DbalUsergroupRepository($this->connection);
        $hash = (new PasswordHasher())->hash(self::PASSWORD);
        $this->admin = $users->create('Admin', 'admin@example.com', $hash, UserStatus::Active)->id;
        $this->member = $users->create('Member', 'member@example.com', $hash, UserStatus::Active)->id;
        $groups->addMember($this->admin, BuiltInGroup::Administrators->value, true);
        $groups->addMember($this->admin, BuiltInGroup::Registered->value);
        $groups->addMember($this->member, BuiltInGroup::Registered->value);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            TestDatabase::release($this->connection);
        }
    }

    public function testGuestsGoToTheLoginAndMembersGet403(): void
    {
        $route = ['_route' => CoreRoutes::ADMIN_DASHBOARD];
        $guest = $this->guard(new ArraySession())->guard($route, Request::create('/admin'));
        $member = $this->guard($this->session($this->member))->guard($route, Request::create('/admin'));
        $admin = $this->guard($this->session($this->admin))->guard($route, Request::create('/admin'));
        $other = $this->guard($this->session($this->member))
            ->guard(['_route' => CoreRoutes::FORUM_INDEX], Request::create('/forums'));

        self::assertNotNull($guest);
        self::assertSame(Response::HTTP_SEE_OTHER, $guest->getStatusCode());
        self::assertSame('/login', $guest->headers->get('Location'));
        self::assertNotNull($member);
        self::assertSame(Response::HTTP_FORBIDDEN, $member->getStatusCode());
        self::assertStringContainsString('is not allowed to open this page', (string) $member->getContent());
        self::assertNull($admin);
        self::assertNull($other, 'other routes are not touched');
    }

    public function testSettingsRegistryUsesTheAdministratorBoundary(): void
    {
        $route = ['_route' => CoreRoutes::ADMIN_SETTINGS];
        self::assertSame(303, $this->guard(new ArraySession())->guard($route, Request::create('/admin/settings'))
            ->getStatusCode());
        self::assertSame(403, $this->guard($this->session($this->member))
            ->guard($route, Request::create('/admin/settings'))->getStatusCode());
        self::assertNull($this->guard($this->session($this->admin))->guard($route, Request::create('/admin/settings')));
    }

    public function testDashboardStatesAndKeyboardStructure(): void
    {
        $session = $this->session($this->admin);
        $empty = $this->dashboard($session)->handle(Request::create('/admin'), []);
        (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'General', 'general');
        $success = $this->dashboard($session)->handle(Request::create('/admin'), []);
        $this->connection->executeStatement('ALTER TABLE forum_statistics RENAME TO forum_statistics_moved');

        try {
            $error = $this->dashboard($session)->handle(Request::create('/admin'), []);
        } finally {
            $this->connection->executeStatement('ALTER TABLE forum_statistics_moved RENAME TO forum_statistics');
        }

        $html = (string) $success->getContent();

        self::assertStringContainsString('There are no forums yet.', (string) $empty->getContent());
        self::assertSame(Response::HTTP_OK, $success->getStatusCode());
        self::assertStringNotContainsString('There are no forums yet.', $html);
        self::assertMatchesRegularExpression('~<dt>Members</dt><dd>2</dd>~', $html);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $error->getStatusCode());
        self::assertStringContainsString('could not be loaded', (string) $error->getContent());
        self::assertStringContainsString('<a class="skip-link" href="#admin-main">', $html);
        self::assertStringContainsString('<nav class="admin-nav" aria-label="Administration">', $html);
        self::assertStringContainsString('aria-current="page">Dashboard</a>', $html);
        self::assertStringContainsString('href="/assets/admin.css"', $html);
        self::assertStringContainsString('href="/assets/accessibility.css"', $html);
        self::assertStringContainsString(
            ':focus-visible',
            (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/accessibility.css'),
        );
        self::assertStringContainsString('src="/assets/admin-commands.js" defer', $html);
        self::assertStringContainsString('data-admin-commands', $html);
        self::assertStringNotContainsString('<script>', $html, 'no inline JavaScript');
    }

    public function testReauthShowsTheOpenWindowAndReturnsToTheAdminPage(): void
    {
        $session = $this->session($this->admin);
        $confirm = fn(string $return) => $this->reauth($session)->handle(Request::create('/admin/reauth', 'POST', [
            'password' => self::PASSWORD,
            'return' => $return,
        ]), [])->headers->get('Location');

        self::assertSame('/admin/members?status=active', $confirm('/admin/members?status=active'));
        self::assertStringContainsString(
            'Your password is confirmed for 60 more minutes.',
            (string) $this->reauth($session)->handle(Request::create('/admin/reauth'), [])->getContent(),
            'an open window is shown, not hidden behind the same prompt',
        );

        foreach (['https://evil.example/admin', '//evil.example/admin', '/forums', '/admin/reauth'] as $bad) {
            self::assertSame('/admin?confirmed=1', $confirm($bad), $bad);
        }

        $form = Request::create('/admin/reauth');
        $form->headers->set('Referer', 'http://localhost/admin/groups/3');
        self::assertStringContainsString(
            'name="return" value="/admin/groups/3"',
            (string) $this->reauth($session)->handle($form, [])->getContent(),
        );

        $user = (new DbalUserRepository($this->connection))->findById($this->admin);
        self::assertNotNull($user);
        $auth = new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies());
        $auth->login($user, false, Request::create('/login/external/google'), confirmsAdmin: false);
        self::assertFalse((new AdminSession($session))->isFresh(), 'an external sign-in never inherits the window');
        // Owner decision 2026-11-17: a sign-in that checked the password (and second factor) opens the window.
        $auth->login($user, false, Request::create('/login'));
        self::assertTrue((new AdminSession($session))->isFresh(), 'a password sign-in confirms the administrator');
    }

    public function testReauthenticationIsAuditedThrottledAndOpensTheWindow(): void
    {
        $session = $this->session($this->admin);
        $wrong = $this->reauth($session)->handle(self::post('nope'), []);
        $right = $this->reauth($session)->handle(self::post(self::PASSWORD), []);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $wrong->getStatusCode());
        self::assertStringNotContainsString('nope', (string) $wrong->getContent());
        self::assertSame([Response::HTTP_SEE_OTHER, '/admin?confirmed=1'], [
            $right->getStatusCode(),
            $right->headers->get('Location'),
        ]);
        self::assertTrue((new AdminSession($session))->isFresh());
        self::assertStringContainsString(
            'Password confirmed.',
            (string) $this->dashboard($session)->handle(Request::create('/admin?confirmed=1'), [])->getContent(),
        );
        self::assertSame(
            ['admin.reauth_failed', 'admin.reauth'],
            $this->connection->fetchFirstColumn(
                "SELECT action FROM audit_logs WHERE action LIKE 'admin.%' ORDER BY id",
            ),
        );

        $status = 0;

        for ($i = 0; $i < 10 && $status !== Response::HTTP_TOO_MANY_REQUESTS; ++$i) {
            $status = $this->reauth($session)->handle(self::post('guess ' . $i), [])->getStatusCode();
        }

        self::assertSame(Response::HTTP_TOO_MANY_REQUESTS, $status, 'repeated failures are throttled');
    }

    public function testSavedFilterAndModeratorHttpBoundaries(): void
    {
        $session = $this->session($this->admin);
        $csrf = new CsrfTokens($session);
        $handler = new SavedFiltersHandler(
            new SavedAdminFilters($this->connection),
            $this->subjects($session),
            $csrf,
            $session,
            self::translator(),
            self::views(),
            self::router(),
            new SettingsCatalog(null, self::translator(), self::router())
        );
        self::assertSame(200, $handler->handle(Request::create('/admin/saved-filters'), [])->getStatusCode());
        $input = ['action' => 'save', 'name' => '<script>Private</script>', 'context' => 'members',
            'f' => ['name' => 'Member', 'before' => '123']];
        self::assertSame(403, $handler->handle(Request::create('/admin/saved-filters', 'POST', $input), [])
            ->getStatusCode());
        $input[CsrfTokens::FIELD] = $csrf->token();
        self::assertSame(303, $handler->handle(Request::create('/admin/saved-filters', 'POST', $input), [])
            ->getStatusCode());
        $response = $handler->handle(Request::create('/admin/saved-filters'), []);
        self::assertStringContainsString('&lt;script&gt;Private&lt;/script&gt;', (string) $response->getContent());
        self::assertStringContainsString('/admin/members?name=Member', (string) $response->getContent());
        self::assertStringNotContainsString('before=123', (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $input['f'] = ['url' => 'https://example.com'];
        self::assertSame(422, $handler->handle(Request::create('/admin/saved-filters', 'POST', $input), [])
            ->getStatusCode());
        $forum = (new DbalForumRepository($this->connection))->create(ForumType::Forum, 'Local', 'local-http')->id;
        $adminSession = new AdminSession($session);
        $moderators = new ForumModeratorsHandler(
            new ForumModeratorAssignments($this->connection),
            new DbalForumRepository($this->connection),
            new DbalUsergroupRepository($this->connection),
            $this->subjects($session),
            $adminSession,
            $csrf,
            $session,
            self::translator(),
            self::views(),
            self::router()
        );
        $input = ['action' => 'assign', 'forum' => (string) $forum, 'member' => 'Member',
            CsrfTokens::FIELD => $csrf->token()];
        self::assertSame(403, $moderators->handle(Request::create('/admin/forum-moderators', 'POST', $input), [])
            ->getStatusCode());
        $adminSession->confirm();
        self::assertSame(303, $moderators->handle(Request::create('/admin/forum-moderators', 'POST', $input), [])
            ->getStatusCode());
        $input[CsrfTokens::FIELD] = 'bad';
        self::assertSame(403, $moderators->handle(Request::create('/admin/forum-moderators', 'POST', $input), [])
            ->getStatusCode());
        self::assertCount(1, (new ForumModeratorAssignments($this->connection))->page(
            $this->subjects($session)->of(Request::create('/admin/forum-moderators'))
        ));
    }

    public function testThemeHttpFreshnessPublicationAndAccountPreference(): void
    {
        $private = new TemporaryDirectory();
        try {
            $session = $this->session($this->admin);
            $csrf = new CsrfTokens($session);
            $admin = new AdminSession($session);
            $builds = new ThemeBuildStore(dirname(__DIR__, 2), $private->path);
            $request = new ThemeRequest();
            $presentation = new ThemePresentation($builds, $session, $request);
            $handler = new ThemesHandler(
                $builds,
                $presentation,
                $this->subjects($session),
                $admin,
                $csrf,
                $session,
                self::views(),
                self::translator(),
                self::router(),
                new ThemeArchive($builds),
                new DbalAdminAudit($this->connection)
            );
            self::assertSame(200, $handler->handle(Request::create('/admin/themes'), [])->getStatusCode());
            $input = ['action' => 'draft', 'theme' => 'default', 'tokens' => '{}', CsrfTokens::FIELD => $csrf->token()];
            self::assertSame(
                403,
                $handler->handle(
                    Request::create(
                        '/admin/themes',
                        'POST',
                        $input
                    ),
                    []
                )->getStatusCode()
            );
            $admin->confirm();
            $input[CsrfTokens::FIELD] = 'invalid';
            self::assertSame(
                403,
                $handler->handle(
                    Request::create(
                        '/admin/themes',
                        'POST',
                        $input
                    ),
                    []
                )->getStatusCode()
            );
            $input[CsrfTokens::FIELD] = $csrf->token();
            self::assertSame(
                303,
                $handler->handle(
                    Request::create(
                        '/admin/themes',
                        'POST',
                        $input
                    ),
                    []
                )->getStatusCode()
            );
            $bad = $input;
            $bad['tokens'] = '<invalid-json>';
            $rejected = $handler->handle(Request::create('/admin/themes', 'POST', $bad), []);
            self::assertSame(422, $rejected->getStatusCode());
            self::assertStringContainsString('&lt;invalid-json&gt;', (string) $rejected->getContent());
            self::assertStringContainsString('value="default" selected', (string) $rejected->getContent());
            self::assertNull($builds->pointer());
            $draft = $builds->draft($this->admin);
            $input = ['action' => 'publish', 'build' => $draft['build'], CsrfTokens::FIELD => $csrf->token()];
            self::assertSame(
                422,
                $handler->handle(
                    Request::create(
                        '/admin/themes',
                        'POST',
                        $input
                    ),
                    []
                )->getStatusCode()
            );
            $input['confirm'] = '1';
            self::assertSame(
                303,
                $handler->handle(
                    Request::create(
                        '/admin/themes',
                        'POST',
                        $input
                    ),
                    []
                )->getStatusCode()
            );
            self::assertSame($draft['build'], $builds->active()['hash']);
            self::assertGreaterThanOrEqual(2, (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'admin.theme_%_requested'",
            ));
            $preferences = new DbalThemePreference($this->connection);
            $appearance = new AppearanceHandler(
                self::views(),
                self::translator(),
                $csrf,
                self::router(),
                $request,
                $this->subjects($session),
                $preferences
            );
            self::assertSame('system', $preferences->get($this->admin));
            $post = Request::create(
                'https://example.test/appearance',
                'POST',
                ['appearance' => 'dark', CsrfTokens::FIELD => $csrf->token()]
            );
            $response = $appearance->handle($post, []);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('dark', $preferences->get($this->admin));
            self::assertSame('system', $preferences->get($this->member));
            self::assertSame('dark', $response->headers->getCookies()[0]->getValue());
            $invalid = Request::create(
                '/appearance',
                'POST',
                ['appearance' => 'url(javascript:bad)', CsrfTokens::FIELD => $csrf->token()]
            );
            self::assertSame(422, $appearance->handle($invalid, [])->getStatusCode());
            self::assertSame('dark', $preferences->get($this->admin));
        } finally {
            $private->remove();
        }
    }

    public function testExtensionsHttpAuthorizationFreshnessCsrfSafeModeAndRecovery(): void
    {
        $private = new TemporaryDirectory();
        try {
            $session = $this->session($this->admin);
            $csrf = new CsrfTokens($session);
            $admin = new AdminSession($session);
            $manager = ExtensionBootstrap::manager($private->path, $private->path . '/storage');
            $handler = new ExtensionsHandler(
                $manager,
                new ExtensionMigrations($this->connection, $manager->catalog),
                $this->subjects($session),
                $admin,
                $csrf,
                self::views(),
                self::translator(),
                self::router(),
                new DbalAdminAudit($this->connection),
            );
            self::assertSame(200, $handler->handle(Request::create('/admin/extensions'), [])->getStatusCode());
            $input = ['action' => 'safe', 'confirm' => '1', CsrfTokens::FIELD => $csrf->token()];
            self::assertSame(403, $handler->handle(
                Request::create('/admin/extensions', 'POST', $input),
                [],
            )->getStatusCode());
            $admin->confirm();
            $bad = $input;
            $bad[CsrfTokens::FIELD] = 'bad';
            self::assertSame(403, $handler->handle(
                Request::create('/admin/extensions', 'POST', $bad),
                [],
            )->getStatusCode());
            self::assertFalse($manager->state->safe());
            self::assertSame(303, $handler->handle(
                Request::create('/admin/extensions', 'POST', $input),
                [],
            )->getStatusCode());
            self::assertTrue($manager->state->safe());
            $input['action'] = 'resume';
            self::assertSame(303, $handler->handle(
                Request::create('/admin/extensions', 'POST', $input),
                [],
            )->getStatusCode());
            self::assertFalse($manager->state->safe());
            self::assertSame(2, (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM audit_logs WHERE action LIKE 'admin.extension_%_requested'",
            ));
        } finally {
            $private->remove();
        }
    }

    public function testExtensionRoutePermissionIsDeniedUntilAnExplicitOwnedGrant(): void
    {
        $registry = new ExtensionRegistry();
        $registry->routes['acme.hello.index'] = [
            'definition' => new RouteDefinition('acme.hello.index', '/extensions/acme/hello/index', ['GET']),
            'permission' => 'acme.hello.view',
        ];
        $catalog = new PermissionCatalog([
            ...array_values(PermissionCatalog::core()->all()), PermissionDefinition::bool('acme.hello.view'),
        ]);
        $subjectsFor = fn(ArraySession $session): RequestSubject => new RequestSubject(
            new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
            new DbalUsergroupRepository($this->connection),
            new PermissionResolver(
                $catalog,
                new DbalPermissionRuleStore($this->connection, $catalog),
                new DbalForumTree($this->connection),
            ),
        );
        $request = Request::create('/extensions/acme/hello/index');
        $parameters = ['_route' => 'acme.hello.index'];
        foreach ([new ArraySession(), $this->session($this->admin)] as $session) {
            $subjects = $subjectsFor($session);
            $guard = new ExtensionRouteGuard(
                $registry,
                static fn(): RequestSubject => $subjects,
                new ErrorResponder(self::translator())
            );
            self::assertSame(403, $guard->guard($parameters, $request)?->getStatusCode());
        }

        $this->connection->insert('user_permission_overrides', [
            'user_id' => $this->admin, 'permission_key' => 'acme.hello.view', 'value' => '1',
        ]);
        $subjects = $subjectsFor($this->session($this->admin));
        $guard = new ExtensionRouteGuard(
            $registry,
            static fn(): RequestSubject => $subjects,
            new ErrorResponder(self::translator())
        );
        self::assertNull($guard->guard($parameters, $request));
        self::assertNull($guard->guard(['_route' => CoreRoutes::ADMIN_EXTENSIONS], $request));
    }

    private function session(int $userId): ArraySession
    {
        $session = new ArraySession();
        $session->set('_user_id', $userId);

        return $session;
    }

    private function guard(ArraySession $session): AdminGuard
    {
        $subjects = $this->subjects($session);

        return new AdminGuard(
            static fn(): RequestSubject => $subjects,
            self::router(),
            new ErrorResponder(self::translator()),
        );
    }

    private function dashboard(ArraySession $session): DashboardHandler
    {
        return new DashboardHandler(
            self::translator(),
            self::views(),
            new DbalAdminStats($this->connection),
            new AdminSession($session),
            self::router(),
        );
    }

    private function reauth(ArraySession $session): ReauthHandler
    {
        return new ReauthHandler(
            self::translator(),
            self::views(),
            $this->subjects($session),
            new DbalUserRepository($this->connection),
            new PasswordHasher(),
            new LoginThrottle(new DbalLoginAttempts($this->connection)),
            new AdminSession($session),
            new DbalAdminAudit($this->connection),
            new CsrfTokens($session),
            self::router(),
        );
    }

    private function subjects(ArraySession $session): RequestSubject
    {
        $catalog = PermissionCatalog::core();

        return new RequestSubject(
            new AuthSession($session, new CsrfTokens($session), new MemoryUsers(), new ResponseCookies()),
            new DbalUsergroupRepository($this->connection),
            new PermissionResolver(
                $catalog,
                new DbalPermissionRuleStore($this->connection, $catalog),
                new DbalForumTree($this->connection),
            ),
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

    private static function post(string $password): Request
    {
        return Request::create('/admin/reauth', 'POST', ['password' => $password]);
    }

    private static function router(): Router
    {
        return new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
    }
}
