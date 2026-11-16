<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Installer;

use Closure;
use Nolviko\Core\Installer\PrerequisiteCheck;
use Nolviko\Core\Installer\PrerequisiteReport;
use Nolviko\Core\Installer\PrerequisiteResult;
use Nolviko\Core\Installer\WebExposureProbe;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * M01.13: each prerequisite outcome is computed, translated and rendered accessibly without JavaScript.
 */
final class PrerequisiteScreenTest extends TestCase
{
    private const EXTENSIONS = ['PDO', 'intl', 'mbstring', 'zip', 'json', 'openssl', 'fileinfo', 'session', 'sodium',
        'gd', 'pdo_mysql'];

    private TemporaryDirectory $site;

    /** @var list<array{0: string, 1: string}> URL and Host of every probe request. */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->site = new TemporaryDirectory();
        mkdir($this->site->path . '/storage');
        mkdir($this->site->path . '/config');
        copy(self::app() . '/config/runtime-policy.php', $this->site->path . '/config/runtime-policy.php');
    }

    protected function tearDown(): void
    {
        $this->site->remove();
    }

    public function testHealthyServerPassesEverythingAndMayContinue(): void
    {
        $report = $this->check($this->server(404))->run(self::request());
        $html = $this->render($report);

        self::assertTrue($report->canContinue);
        self::assertSame([], self::statuses($report, PrerequisiteResult::FAILED));
        self::assertSame([], self::statuses($report, PrerequisiteResult::WARNING));
        self::assertStringContainsString('PHP 8.4.27 is supported.', $html);
        self::assertStringContainsString('The PHP extension sodium is loaded.', $html);
        self::assertStringContainsString('The PHP memory limit is 128M.', $html);
        self::assertStringContainsString('The storage directory is writable by the web server.', $html);
        self::assertStringContainsString('The vendor/ directory cannot be downloaded from the web.', $html);
        self::assertStringContainsString(self::text('installer.prerequisites.ready'), $html);
    }

    public function testEveryFailureIsShownWithTranslatedGuidance(): void
    {
        chmod($this->site->path . '/storage', 0500);
        $runtime = ['version' => '8.4.27-1ubuntu1', 'extensions' => ['PDO', 'json'], 'memory_limit' => '64M'];
        $report = $this->check($this->server(404), $runtime)->run(self::request());
        $html = $this->render($report);
        chmod($this->site->path . '/storage', 0700);

        self::assertFalse($report->canContinue);
        self::assertStringContainsString('PHP 8.4.27-1ubuntu1 is not supported.', $html);
        self::assertStringContainsString('distribution suffix', $html);
        self::assertStringContainsString('The required PHP extension intl is not loaded.', $html);
        self::assertStringContainsString('The required PHP extension pdo_mysql is not loaded.', $html);
        self::assertStringContainsString('The PHP memory_limit must be at least 128 MB.', $html);
        self::assertStringContainsString(self::text('installer.prerequisites.blocked'), $html);
        self::assertStringContainsString('<strong>Failed:</strong>', $html);

        if (DIRECTORY_SEPARATOR === '/' && function_exists('posix_geteuid') && posix_geteuid() !== 0) {
            self::assertStringContainsString(self::text('diagnostics.storage_not_writable'), $html);
        }
    }

    public function testInvalidMemoryLimitIsReported(): void
    {
        $runtime = ['memory_limit' => 'lots'];
        $html = $this->render($this->check($this->server(404), $runtime)->run(self::request()));

        self::assertStringContainsString(self::text('diagnostics.memory_limit_invalid'), $html);
    }

    public function testExposedAndUntestableDirectoriesAreWarnings(): void
    {
        $report = $this->check($this->server(200))->run(self::request());
        $html = $this->render($report);
        $unknown = $this->render($this->check(static fn(): ?array => null)->run(self::request()));

        self::assertTrue($report->canContinue, 'exposure warns but does not block');
        self::assertCount(3, self::statuses($report, PrerequisiteResult::WARNING));
        self::assertStringContainsString('Files in the storage/ directory can be downloaded from the web.', $html);
        self::assertStringContainsString('Files in the config/ directory can be downloaded', $html);
        self::assertStringContainsString('Files in the vendor/ directory can be downloaded', $html);
        self::assertStringContainsString('<strong>Warning:</strong>', $html);
        self::assertStringContainsString('Could not test over HTTP whether the storage/ directory', $unknown);
    }

    public function testPortFromHostHeaderFallsBackToTheDefaultPort(): void
    {
        // Apache with UseCanonicalName Off reports the Host header's port (8080) although it listens on 80.
        $request = new Request(server: ['HTTP_HOST' => 'forum.example:8080', 'SERVER_ADDR' => '10.0.0.5',
            'SERVER_PORT' => '8080', 'SCRIPT_NAME' => '/index.php', 'REQUEST_URI' => '/install']);
        $server = $this->server(404);
        $fetch = static fn(string $url, string $host): ?array
            => str_starts_with($url, 'http://10.0.0.5:80/') ? $server($url, $host) : null;

        $results = (new WebExposureProbe($fetch))->probe($request, $this->site->path, $this->site->path . '/storage');

        self::assertSame(array_fill_keys(['storage', 'config', 'vendor'], WebExposureProbe::PROTECTED), $results);
        self::assertCount(3, $this->requests, 'after the fallback answered, only port 80 is asked');
    }

    public function testFirstUnansweredRequestStopsFurtherProbes(): void
    {
        $calls = 0;
        $fetch = static function () use (&$calls): ?array {
            ++$calls;

            return null;
        };

        $probe = new WebExposureProbe($fetch);
        $results = $probe->probe(self::request(), $this->site->path, $this->site->path . '/storage');

        self::assertSame(1, $calls, 'a single-worker host waits for one timeout only');
        self::assertSame(array_fill_keys(['storage', 'config', 'vendor'], WebExposureProbe::UNKNOWN), $results);
    }

    public function testProbeTargetsTheServerItselfAndCleansUp(): void
    {
        // Behind a port mapping: the browser uses :8080, Apache itself listens on 10.0.0.5:80.
        $request = new Request(server: [
            'HTTP_HOST' => 'forum.example:8080',
            'SERVER_NAME' => 'forum.example',
            'SERVER_ADDR' => '10.0.0.5',
            'SERVER_PORT' => '80',
            'SCRIPT_NAME' => '/board/public/index.php',
            'SCRIPT_FILENAME' => '/var/www/board/public/index.php',
            'REQUEST_URI' => '/board/public/install',
        ]);

        $this->check($this->server(404))->run($request);

        self::assertCount(3, $this->requests);

        foreach ($this->requests as [$url, $host]) {
            self::assertStringStartsWith('http://10.0.0.5:80/board/', $url);
            self::assertSame('forum.example:8080', $host);
        }

        self::assertMatchesRegularExpression('~/board/storage/\.probe-[0-9a-f]{16}\.txt$~', $this->requests[0][0]);
        self::assertStringEndsWith('/board/vendor/composer/installed.json', $this->requests[2][0]);
        self::assertSame([], glob($this->site->path . '/{storage,config}/.probe-*', GLOB_BRACE) ?: []);
    }

    /**
     * @return array<string, array{0: int|null, 1: string}>
     */
    public static function answers(): array
    {
        return [
            'forbidden' => [403, WebExposureProbe::PROTECTED],
            'not found' => [404, WebExposureProbe::PROTECTED],
            'redirect' => [301, WebExposureProbe::UNKNOWN],
            'server error' => [500, WebExposureProbe::UNKNOWN],
            'no answer' => [null, WebExposureProbe::UNKNOWN],
            'served' => [200, WebExposureProbe::EXPOSED],
        ];
    }

    #[DataProvider('answers')]
    public function testAnswersAreClassified(?int $status, string $expected): void
    {
        $fetch = $status === null ? static fn(): ?array => null : $this->server($status);
        $probe = new WebExposureProbe($fetch);
        $results = $probe->probe(self::request(), $this->site->path, $this->site->path . '/storage');

        self::assertSame($expected, $results['storage']);
    }

    public function testOtherContentWithStatus200IsNotExposure(): void
    {
        $results = (new WebExposureProbe(static fn(): array => [200, '<!doctype html>front controller']))
            ->probe(self::request(), $this->site->path, $this->site->path . '/storage');

        self::assertSame(array_fill_keys(['storage', 'config', 'vendor'], WebExposureProbe::PROTECTED), $results);
    }

    public function testPageIsAccessibleWithoutScript(): void
    {
        $html = $this->render($this->check($this->server(404))->run(self::request()));

        self::assertStringContainsString('<html lang="en" dir="ltr" data-appearance="system">', $html);
        self::assertSame(1, substr_count($html, '<h1>'));
        self::assertSame(2, substr_count($html, '<section aria-labelledby="group-'));
        self::assertSame(2, substr_count($html, '<h2 id="group-'));
        self::assertStringContainsString('<p role="status">', $html);
        self::assertStringContainsString('<a href="/install">' . self::text('installer.prerequisites.recheck'), $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    /**
     * Fake web server: answers $status; with 200 it serves the requested canary or Composer's installed.json.
     *
     * @return Closure(string, string): array{0: int, 1: string}
     */
    private function server(int $status): Closure
    {
        return function (string $url, string $host) use ($status): array {
            $this->requests[] = [$url, $host];

            if ($status !== 200) {
                return [$status, 'denied'];
            }

            if (str_ends_with($url, 'installed.json')) {
                return [200, '{"packages": []}'];
            }

            $path = (string) parse_url($url, PHP_URL_PATH);
            $file = $this->site->path . '/' . preg_replace('~^.*/(storage|config)/~', '$1/', $path);

            return [200, (string) @file_get_contents($file)];
        };
    }

    /**
     * @param array{version?: string, extensions?: list<string>, memory_limit?: string} $runtime
     */
    private function check(Closure $fetch, array $runtime = []): PrerequisiteCheck
    {
        return new PrerequisiteCheck(
            $this->site->path,
            $this->site->path . '/storage',
            new WebExposureProbe($fetch),
            'mysql',
            static fn(): string => '2026-10-31',
            $runtime + ['version' => '8.4.27', 'extensions' => self::EXTENSIONS, 'memory_limit' => '128M'],
        );
    }

    private function render(PrerequisiteReport $report): string
    {
        return TwigFactory::create(self::app(), $this->site->path . '/no-storage', self::translator())->render(
            'installer/prerequisites.twig',
            [
                'language' => 'en',
                'title' => 'Install',
                'report' => $report,
                'recheck_url' => '/install',
                'database_url' => '/install/database',
            ],
        );
    }

    /**
     * @return list<PrerequisiteResult>
     */
    private static function statuses(PrerequisiteReport $report, string $status): array
    {
        return array_values(array_filter(
            [...$report->runtime, ...$report->files],
            static fn(PrerequisiteResult $result): bool => $result->status === $status,
        ));
    }

    private static function request(): Request
    {
        return Request::create('http://forum.example/install');
    }

    private static function text(string $id): string
    {
        return self::translator()->translate($id);
    }

    private static function translator(): MessageTranslator
    {
        return TranslatorFactory::create(self::app() . '/translations', 'en');
    }

    private static function app(): string
    {
        return dirname(__DIR__, 3);
    }
}
