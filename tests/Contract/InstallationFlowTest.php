<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Nolviko\Core\Http\HtmlPage;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Tests\Support\SiteFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * M01.05: no config → requests go to the installer; config plus install lock → installer routes are 404.
 */
final class InstallationFlowTest extends TestCase
{
    public function testWithoutConfigMatchedRoutesGoToTheInstaller(): void
    {
        $site = SiteFixture::notConfigured();

        try {
            $home = $site->respond(Request::create('/'));
            $installer = $site->respond(Request::create('/install'));
            $unknown = $site->respond(Request::create('/no/such/page'));
        } finally {
            $site->remove();
        }

        self::assertSame(Response::HTTP_FOUND, $home->getStatusCode());
        self::assertSame('/install', $home->headers->get('Location'));
        self::assertSame(Response::HTTP_OK, $installer->getStatusCode());
        self::assertStringContainsString(self::text('core.installer.title'), (string) $installer->getContent());
        self::assertSame(Response::HTTP_NOT_FOUND, $unknown->getStatusCode());
    }

    public function testInstallerRedirectKeepsTheBasePath(): void
    {
        $site = SiteFixture::notConfigured();

        try {
            $response = $site->respond(Request::create('/forum/', server: [
                'SCRIPT_NAME' => '/forum/index.php',
                'SCRIPT_FILENAME' => '/var/www/forum/index.php',
                'PHP_SELF' => '/forum/index.php',
            ]));
        } finally {
            $site->remove();
        }

        self::assertSame('/forum/install', $response->headers->get('Location'));
    }

    public function testConfigWithoutLockStillServesOnlyTheInstaller(): void
    {
        $site = SiteFixture::configured();

        try {
            $home = $site->respond(Request::create('/'));
            $installer = $site->respond(Request::create('/install'));
        } finally {
            $site->remove();
        }

        self::assertSame(Response::HTTP_FOUND, $home->getStatusCode());
        self::assertSame(Response::HTTP_OK, $installer->getStatusCode());
    }

    public function testInstalledSiteHidesTheInstaller(): void
    {
        $site = SiteFixture::installed();

        try {
            $home = $site->respond(Request::create('/robots.txt'));
            $installer = $site->respond(Request::create('/install'));
            $head = $site->respond(Request::create('/install', 'HEAD'));
        } finally {
            $site->remove();
        }

        self::assertSame(Response::HTTP_OK, $home->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $installer->getStatusCode());
        self::assertSame(Response::HTTP_NOT_FOUND, $head->getStatusCode());
    }

    public function testLockWithoutConfigNeverReopensTheInstaller(): void
    {
        $site = SiteFixture::notConfigured();
        $site->lock();

        try {
            $installer = $site->respond(Request::create('/install'));
        } finally {
            $site->remove();
        }

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $installer->getStatusCode());
        self::assertStringContainsString(
            HtmlPage::escape(self::text('diagnostics.config_missing')),
            (string) $installer->getContent(),
        );
    }

    public function testInvalidConfigIsA503ThatNamesKeysButNoValues(): void
    {
        $site = SiteFixture::installed();
        $values = SiteFixture::validConfig();
        $values['database']['port'] = SiteFixture::DB_PASSWORD;
        $values['debug'] = SiteFixture::APP_SECRET;
        $site->writeConfig($values);

        try {
            $response = $site->respond(Request::create('/'));
        } finally {
            $site->remove();
        }

        $body = (string) $response->getContent();

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertStringContainsString(
            HtmlPage::escape(self::text('diagnostics.config_key_unknown', ['key' => 'debug'])),
            $body,
        );
        self::assertStringContainsString(
            HtmlPage::escape(self::text('diagnostics.config_type_invalid', ['key' => 'database.port'])),
            $body,
        );
        self::assertStringNotContainsString(SiteFixture::DB_PASSWORD, $body);
        self::assertStringNotContainsString(SiteFixture::APP_SECRET, $body);
    }

    /**
     * @param array<string, string> $parameters
     */
    private static function text(string $id, array $parameters = []): string
    {
        return TranslatorFactory::create(SiteFixture::root() . '/translations', TranslatorFactory::FALLBACK_LOCALE)
            ->translate($id, $parameters);
    }
}
