<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Http\FrontController;
use Nolviko\Core\Http\HtmlPage;
use Nolviko\Core\Http\IncidentId;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\NativeSession;
use Nolviko\Tests\Support\RecordingReporter;
use Nolviko\Tests\Support\SiteFixture;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional M01.02/M01.03 contract on an installed site: 200/301/400/404/405/500/503, route cache, no leaks.
 */
final class FrontControllerTest extends TestCase
{
    private SiteFixture $site;

    protected function setUp(): void
    {
        $this->site = SiteFixture::installed();
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testOldForumsAddressRedirectsToTheHomeBoardIndex(): void
    {
        // The board index is served at / (2026-11-07); /forums stays as a permanent redirect for old links.
        $response = $this->site->respond(Request::create('/forums'));

        self::assertSame(Response::HTTP_MOVED_PERMANENTLY, $response->getStatusCode());
        self::assertSame('/', $response->headers->get('Location'));
    }

    public function testPlainPageIsTranslatedHtml(): void
    {
        $response = $this->site->respond(Request::create('/search'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringStartsWith(
            'text/html; charset=UTF-8',
            (string) $response->headers->get('Content-Type')
        );
        self::assertStringContainsString('<html lang="en" dir="ltr"', (string) $response->getContent());
    }

    public function testAnonymousGetStartsNoSession(): void
    {
        $plain = $this->site->respond(Request::create('/robots.txt'));
        $stale = $this->site->respond(Request::create('/robots.txt', cookies: [NativeSession::COOKIE => 'x']));

        self::assertSame([], $plain->headers->getCookies());
        self::assertSame([], $stale->headers->getCookies(), 'a malformed cookie is ignored, not answered');
        self::assertDirectoryDoesNotExist($this->site->storagePath() . '/' . NativeSession::DIRECTORY);
    }

    public function testHeadIsAllowedOnGetPages(): void
    {
        self::assertSame(Response::HTTP_OK, $this->site->respond(Request::create(
            '/robots.txt',
            'HEAD'
        ))->getStatusCode());
    }

    public function testUnknownRouteReturnsTranslated404(): void
    {
        $response = $this->site->respond(Request::create('/no/such/page'));

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString(
            self::text('core.error.not_found.title'),
            (string) $response->getContent()
        );
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        $response = $this->site->respond(Request::create('/', 'POST'));

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
        self::assertSame('GET, HEAD', $response->headers->get('Allow'));
    }

    public function testRequestIsCreatedOnlyAfterMethodOverrideIsDisabled(): void
    {
        Request::setAllowedHttpMethodOverride(['DELETE']);
        $overridesAtCreation = null;

        $response = $this->site->respond(static function () use (&$overridesAtCreation): Request {
            $overridesAtCreation = Request::getAllowedHttpMethodOverride();

            return Request::create('/', 'POST', ['_method' => 'GET']);
        });

        self::assertSame([], $overridesAtCreation);
        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());
    }

    public function testUnexpectedFailureReturns500WithIncidentAndNoDetails(): void
    {
        $reporter = new RecordingReporter();
        $error = new RuntimeException(
            'SQLSTATE[HY000]: SELECT password FROM users; db_password=hunter2 in ' . SiteFixture::root(),
        );

        $response = $this->site->respond(static fn(): Request => throw $error, $reporter);
        $body = (string) $response->getContent();

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertCount(1, $reporter->incidents);
        $incidentId = (string) array_key_first($reporter->incidents);
        self::assertMatchesRegularExpression(IncidentId::PATTERN, $incidentId);
        self::assertSame($error, $reporter->incidents[$incidentId]);
        self::assertStringContainsString(
            HtmlPage::escape(self::text('core.error.server.body', ['incident' => $incidentId])),
            $body,
        );

        $leaks = ['SQLSTATE', 'SELECT', 'hunter2', 'RuntimeException', '#0', __FILE__, SiteFixture::root(),
            SiteFixture::DB_PASSWORD, SiteFixture::APP_SECRET];

        foreach ($leaks as $leak) {
            self::assertStringNotContainsString($leak, $body);
        }
    }

    public function testIncidentIdsAreRandom(): void
    {
        $reporter = new RecordingReporter();

        for ($i = 0; $i < 3; ++$i) {
            $this->site->respond(static fn(): Request => throw new RuntimeException('boom'), $reporter);
        }

        self::assertCount(3, $reporter->incidents);
    }

    public function testRuntimePolicyFailureReturns503WithTranslatedDiagnostics(): void
    {
        $site = SiteFixture::notConfigured();
        $requestCreated = false;

        try {
            $response = $site->respond(static function () use (&$requestCreated): Request {
                $requestCreated = true;

                return Request::create('/');
            }, environment: [FrontController::DRIVER_ENVIRONMENT => 'unsupported']);
        } finally {
            $site->remove();
        }

        $body = (string) $response->getContent();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertFalse($requestCreated);
        self::assertStringContainsString(self::text('core.error.unavailable.title'), $body);
        self::assertStringContainsString(
            '<li>' . HtmlPage::escape(self::text('diagnostics.database_driver_unsupported')) . '</li>',
            $body,
        );
        self::assertStringContainsString(self::text('core.error.unavailable.count', ['count' => 1]), $body);
    }

    public function testInvalidProjectDirectoryReturns500NotFatal(): void
    {
        $reporter = new RecordingReporter();
        $response = $this->site->respond(
            Request::create('/'),
            $reporter,
            projectDirectory: SiteFixture::root() . '/missing',
        );

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertCount(1, $reporter->incidents);
        self::assertStringNotContainsString('/missing', (string) $response->getContent());
    }

    public function testRoutesAreCachedPerReleaseInStorageAndPolicyStatusesHold(): void
    {
        $storage = $this->site->storagePath();
        mkdir($storage);
        $respond = fn(string $uri): Response => $this->site->respond(
            Request::create($uri),
            environment: [FrontController::STORAGE_ENVIRONMENT => $storage],
        );

        self::assertSame(Response::HTTP_OK, $respond('/robots.txt')->getStatusCode());
        self::assertCount(1, glob($storage . '/cache/routes/routes-dev-*.php') ?: []);
        self::assertSame(Response::HTTP_OK, $respond('/robots.txt')->getStatusCode());
        self::assertSame(Response::HTTP_BAD_REQUEST, $respond('/%2e%2e/config')->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $respond('/index.php')->getStatusCode());
    }

    public function testEveryCoreRouteHasAHandler(): void
    {
        $config = ConfigLoader::load($this->site->configDirectory . '/local.php', []);
        $router = FrontController::services(
            SiteFixture::root(),
            $config,
            InstallationState::Installed,
            new RecordingReporter(),
            [FrontController::STORAGE_ENVIRONMENT => SiteFixture::root() . '/missing-storage'],
        )->service(Router::class);

        foreach (CoreRoutes::definitions() as $definition) {
            // The login form starts a real PHP session, which cannot start inside PHPUnit's process once output
            // exists; LoginFlowTest covers it with an in-memory session, NativeSessionTest the real one.
            // The board index and thread pages read the database for guests too and this fixture has none;
            // ForumIndexTest and ThreadPageTest render them through the same handlers. (`threads.page` with the
            // sample page 1 only redirects, so it stays in.)
            $needsDatabase = [
                CoreRoutes::HOME,
                CoreRoutes::SITEMAP_INDEX,
                CoreRoutes::SITEMAP_FORUMS,
                CoreRoutes::SITEMAP_THREADS,
                CoreRoutes::ADMIN_IMPORTS,
                CoreRoutes::ADMIN_DIAGNOSTICS,
                CoreRoutes::IMPORT_PRIVATE_FILE,
                CoreRoutes::LEGACY_RECORD,
                CoreRoutes::LEGACY_URL,
                CoreRoutes::ADMIN_TASKS,
                CoreRoutes::ADMIN_CACHE,
                CoreRoutes::COMMUNITY,
                CoreRoutes::COMMUNITY_MEDIA,
                CoreRoutes::LOGIN,
                CoreRoutes::APPEARANCE,
                CoreRoutes::FORUM_INDEX,
                CoreRoutes::FORUM_SHOW,
                CoreRoutes::THREAD_SHOW,
                // The slug and page-1 redirects come after the read check (2026-11-14).
                CoreRoutes::THREAD_PAGE,
                CoreRoutes::THREAD_ID,
                CoreRoutes::THREAD_ID_PAGE,
                // GET shows the logout confirmation, which reads the session (2026-11-14).
                CoreRoutes::LOGOUT,
                CoreRoutes::POST_SHOW,
                CoreRoutes::POST_HISTORY,
                CoreRoutes::TAG_SHOW,
                CoreRoutes::GALLERY,
                CoreRoutes::MEMBER_LINK,
                CoreRoutes::MEMBER_SUGGEST,
                CoreRoutes::API_FORUMS,
                CoreRoutes::API_FORUM_THREADS,
                CoreRoutes::API_THREADS,
                CoreRoutes::API_THREAD,
                CoreRoutes::INDEXNOW_KEY,
                CoreRoutes::GALLERY_ALBUM_NEW,
                CoreRoutes::GALLERY_ALBUM,
                CoreRoutes::GALLERY_ITEM,
                CoreRoutes::GALLERY_FILE,
                CoreRoutes::GALLERY_PENDING,
                CoreRoutes::MODERATION_REPORT,
                CoreRoutes::MODERATION_BULK,
                CoreRoutes::MODERATION_USER,
                CoreRoutes::ADMIN_BANS,
                CoreRoutes::ADMIN_MAIL,
                CoreRoutes::ADMIN_REGISTRATION,
                CoreRoutes::ADMIN_AUDIT,
                CoreRoutes::ADMIN_PERMISSIONS,
                CoreRoutes::ADMIN_MEMBERS,
                CoreRoutes::ADMIN_MEMBERS_BULK,
                CoreRoutes::ADMIN_FORUMS,
                CoreRoutes::ADMIN_FORUMS_ACTION,
                CoreRoutes::FORUM_READ,
                CoreRoutes::REGISTER,
                CoreRoutes::VERIFY_EMAIL,
                CoreRoutes::VERIFY_RESEND,
                CoreRoutes::PASSWORD_FORGOT,
                CoreRoutes::PASSWORD_RESET,
                CoreRoutes::ACCOUNT,
                CoreRoutes::ACCOUNT_PASSWORD,
                CoreRoutes::ACCOUNT_EMAIL,
                CoreRoutes::ACCOUNT_EMAIL_CONFIRM,
                // HTTPS is optional (2026-11-09), so these reach the database over plain HTTP too.
                CoreRoutes::CONVERSATIONS,
                CoreRoutes::CONVERSATIONS_NEW,
                CoreRoutes::CONVERSATION,
                CoreRoutes::ACCOUNT_PRIVACY,
                // beta.18 member, forum and content pages read the database.
                CoreRoutes::FORUMS_READ_ALL,
                CoreRoutes::THREAD_UNREAD,
                CoreRoutes::DRAFTS,
                CoreRoutes::FOLLOWED,
                CoreRoutes::GROUPS,
                CoreRoutes::FORUM_LINK,
                CoreRoutes::FORUM_UNLOCK,
                CoreRoutes::FORUM_SUBSCRIBE,
                CoreRoutes::CALENDAR,
                // Its key and extension requirements are not numeric; covered by its own handler.
                CoreRoutes::SITE_IMAGE,
            ];

            if (in_array($definition->name, $needsDatabase, true)) {
                continue;
            }

            $sample = array_map(static fn(): string => '1', $definition->requirements);
            $response = $this->site->respond(Request::create($router->generate(
                $definition->name,
                $sample
            )));
            self::assertNotSame(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                $response->getStatusCode(),
                $definition->name
            );
        }
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private static function text(string $id, array $parameters = []): string
    {
        return self::translator()->translate($id, $parameters);
    }

    private static function translator(): MessageTranslator
    {
        return TranslatorFactory::create(
            SiteFixture::root() . '/translations',
            TranslatorFactory::FALLBACK_LOCALE
        );
    }
}
