<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Nolviko\Core\Runtime\Bootstrap;
use Nolviko\Tests\Fixtures\SelectedComponents;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class FoundationContractTest extends TestCase
{
    /** Exact runtime versions selected by ADR-005..008 and ADR-022. */
    private const SELECTED_VERSIONS = [
        'symfony/http-foundation' => '7.4.23',
        'symfony/routing' => '7.4.23',
        'symfony/translation' => '7.4.22',
        'doctrine/dbal' => '4.5.0',
        'twig/twig' => '3.30.0',
        'composer/semver' => '3.5.2',
        'symfony/mailer' => '7.4.22',
        'symfony/mime' => '7.4.22',
    ];

    public function testRealLockAndPsr4MatchSelection(): void
    {
        $lock = self::readJson('composer.lock');
        $manifest = self::readJson('composer.json');
        $lockedVersions = array_column($lock['packages'], 'version', 'name');

        foreach (self::SELECTED_VERSIONS as $name => $version) {
            self::assertSame($version, ltrim($lockedVersions[$name], 'v'));
        }

        self::assertCount(22, $lock['packages'], 'ADR-025 adds semver to the approved ADR-028 runtime stack');
        self::assertArrayNotHasKey('symfony/polyfill-intl-idn', $lockedVersions);
        self::assertArrayNotHasKey('phpunit/phpunit', $lockedVersions);
        self::assertArrayNotHasKey('laravel/framework', $lockedVersions);
        self::assertArrayNotHasKey('symfony/framework-bundle', $lockedVersions);
        self::assertSame('src/', $manifest['autoload']['psr-4']['Nolviko\\']);
    }

    public function testRewrittenRequestCannotTurnTheBaseUrlIntoAClientChosenHost(): void
    {
        $request = new Request(server: [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '//evil.example/public/index.php/login',
            'SCRIPT_NAME' => '/public/index.php', 'SCRIPT_FILENAME' => '/srv/app/public/index.php',
            'PHP_SELF' => '/public/index.php', 'HTTP_HOST' => 'forum.example',
            'SERVER_NAME' => 'forum.example', 'SERVER_PORT' => '443', 'HTTPS' => 'on',
        ]);
        self::assertFalse(str_starts_with($request->getBaseUrl(), '//'));
        self::assertFalse(str_starts_with($request->getBasePath(), '//'));
    }

    public function testNativeComponentCompositionWithoutDatabase(): void
    {
        Bootstrap::boot(self::root());
        $components = new SelectedComponents();

        $response = $components->render(Request::create('/threads/42'));
        $content = $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(20, substr_count($content, '<article>'));
        self::assertStringContainsString('<h1>Topics</h1>', $content);
        self::assertStringNotContainsString('<script>', $content);
        self::assertStringContainsString('العربية 😀', $content);
        self::assertSame('2 posts', $components->plural(2));
        self::assertFalse($components->connected());
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $file): array
    {
        return json_decode(file_get_contents(self::root() . '/' . $file), true, flags: JSON_THROW_ON_ERROR);
    }
}
