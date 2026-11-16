<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Admin;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\ConfigSchema;
use Nolviko\Core\Config\MailSettings;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\SettingsCatalog;
use Nolviko\Modules\Admin\Http\SettingsRegistryHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

/** Only whitelisted nonsecret configuration enters the read-only administration search. */
final class SettingsRegistryTest extends TestCase
{
    public function testEveryEntryUsesARealTypedKeyAndNeverContainsCredentials(): void
    {
        $rows = $this->catalog()->search('');
        self::assertCount(19, $rows);

        foreach ($rows as $row) {
            self::assertArrayHasKey($row['key'], ConfigSchema::KEYS);
            self::assertNotEmpty($row['label']);
        }

        $encoded = json_encode($rows, JSON_THROW_ON_ERROR);

        foreach (
            ['private-db-host', 'private-db-name', 'private-db-user', 'private-db-password',
            'private-app-secret', 'private-smtp-host', 'private-smtp-user', 'private-smtp-password'] as $secret
        ) {
            self::assertStringNotContainsString($secret, $encoded);
        }

        self::assertSame([], $this->catalog()->search('private'));
        self::assertSame('Whole number', $this->catalog()->search('forums.max_depth')[0]['type']);
        self::assertSame('Automatic / default', $this->catalog()->search('forums.max_depth')[0]['value']);
    }

    public function testKeyNameAndGroupSearchUseAllTermsWithLocalizedCase(): void
    {
        self::assertSame('registration.ip_interval_seconds', $this->catalog()->search('registration IP')[0]['key']);
        self::assertCount(3, $this->catalog()->search('', 'mail'));
        self::assertCount(2, $this->catalog()->search('SMTP', 'mail'));
        self::assertCount(3, $this->catalog('tr')->search('GÜVENLİK'));
        self::assertCount(3, $this->catalog('tr')->search("GU\u{0308}VENLI\u{0307}K"));
        self::assertSame('registration.mode', $this->catalog('tr')->search('REGISTRATION ÜYELİK MODU')[0]['key']);
        self::assertSame([], $this->catalog()->search('nothing matches this query'));
        self::assertSame([], $this->catalog()->search('registration', 'mail'));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidQueries(): array
    {
        return ['too long' => [str_repeat('a', 121), ''], 'control' => ["a\0b", ''],
            'invalid utf8' => ["\xff", ''], 'unknown group' => ['', 'unknown']];
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidSearchInputIsRejected(string $query, string $group): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->catalog()->search($query, $group);
    }

    public function testHandlerRendersCurrentValuesWithoutMutationAndEscapesTheQuery(): void
    {
        $response = $this->handler()->handle(Request::create('/admin/settings?q=threads.per_page'), []);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('threads.per_page', (string) $response->getContent());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $dom = new DOMDocument();
        @$dom->loadHTML((string) $response->getContent());
        self::assertSame(0, (new DOMXPath($dom))->query('//form[@method="post"]')->length);
        $request = Request::create('/admin/settings', 'GET', ['q' => '<script>alert(1)</script>']);
        $html = (string) $this->handler()->handle($request, [])->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testHandlerReportsInvalidArraysAndUnavailableConfigurationSafely(): void
    {
        $response = $this->handler()->handle(Request::create('/admin/settings', 'GET', ['q' => ['bad']]), []);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('role="alert"', (string) $response->getContent());
        self::assertSame(503, $this->handler(false)->handle(Request::create('/admin/settings'), [])->getStatusCode());
    }

    public function testSettingsRouteIsHttpsAndReadOnly(): void
    {
        $route = array_values(array_filter(
            CoreRoutes::definitions(),
            static fn(RouteDefinition $route): bool => $route->name === CoreRoutes::ADMIN_SETTINGS
        ))[0];
        self::assertTrue($route->requiresHttps);
        $this->expectException(MethodNotAllowedException::class);
        $this->router()->match(Request::create('/admin/settings', 'POST'));
    }

    private function config(): Config
    {
        return new Config(
            'mysql',
            'private-db-host',
            null,
            'private-db-name',
            'private-db-user',
            new Secret('private-db-password'),
            new Secret('private-app-secret'),
            defaultLocale: 'tr',
            threadsPerPage: 25,
            mail: new MailSettings(
                transport: 'smtp',
                smtpHost: 'private-smtp-host',
                smtpUser: 'private-smtp-user',
                smtpPassword: new Secret('private-smtp-password')
            )
        );
    }

    private function router(): Router
    {
        return new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
    }

    private function catalog(string $locale = 'en'): SettingsCatalog
    {
        return new SettingsCatalog(
            $this->config(),
            TranslatorFactory::create(dirname(__DIR__, 3) . '/translations', $locale),
            $this->router()
        );
    }

    private function handler(bool $configured = true): SettingsRegistryHandler
    {
        $app = dirname(__DIR__, 3);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $router = $this->router();

        return new SettingsRegistryHandler(
            new SettingsCatalog($configured ? $this->config() : null, $translator, $router),
            $translator,
            TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-settings-tests', $translator),
            $router
        );
    }
}
