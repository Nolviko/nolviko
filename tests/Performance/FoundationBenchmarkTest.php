<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Http\FrontController;
use Nolviko\Core\Http\MaintenanceGuard;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Logging\JsonFileLogger;
use Nolviko\Core\Logging\Redactor;
use Nolviko\Core\Runtime\Bootstrap;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Editor\Domain\BbcodeRenderer;
use Nolviko\Modules\Permissions\Domain\ArrayForumTree;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Tests\Fixtures\SelectedComponents;
use Nolviko\Tests\Support\ArraySession;
use Nolviko\Tests\Support\MemoryRuleStore;
use Nolviko\Tests\Support\RecordingReporter;
use Nolviko\Tests\Support\SiteFixture;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

#[Group('benchmark')]
final class FoundationBenchmarkTest extends TestCase
{
    public function testBootstrapAndNativeFixture(): void
    {
        $root = dirname(__DIR__, 2);
        Bootstrap::boot($root);
        $components = new SelectedComponents();
        $request = Request::create('/threads/42');

        self::assertSame(20, substr_count($components->render($request)->getContent(), '<article>'));
        self::assertFalse($components->connected());
        $maintenance = SiteFixture::installed();
        touch($maintenance->configDirectory . '/' . MaintenanceGuard::FILE);
        $site = SiteFixture::installed();
        $config = ConfigLoader::load($site->configDirectory . '/local.php', []);
        // `/` is the board index since 2026-11-07 and needs a database; robots.txt is the lightest database-free
        // route through the full front controller, like the old placeholder home page this case was built on.
        self::assertSame(200, $site->respond(Request::create('/robots.txt'))->getStatusCode());
        $logs = new TemporaryDirectory();
        $logger = new JsonFileLogger($logs->path, new Redactor([SiteFixture::DB_PASSWORD]));
        $exception = new RuntimeException('password=' . SiteFixture::DB_PASSWORD, 1, new RuntimeException('cause'));
        $cacheStorage = new TemporaryDirectory();
        $cache = (new CacheFactory($cacheStorage->path))->namespace('bench');
        $cached = ['forums' => array_fill(0, 50, ['id' => 1, 'title' => 'Forum title', 'posts' => 12345])];
        $cache->set('hit', $cached);
        $csrf = new CsrfTokens(new ArraySession());
        $english = TranslatorFactory::create($root . '/translations', 'en');
        $views = TwigFactory::create($root, $cacheStorage->path, $english);
        $page = ['language' => 'en', 'title' => 'Forum <title>'];
        $views->render('layouts/page.twig', $page);
        $argon2 = new PasswordHasher();
        $bcrypt = new PasswordHasher(argon2: false);
        $argon2Hash = $argon2->hash('correct horse battery staple');
        [$permissionResolver, $permissionKeys] = self::permissionFixture();
        $bbcode = new BbcodeRenderer();
        $post8kb = self::post8kb();

        $report = [
            'profile' => 'm01-foundation-v1',
            'php' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'opcache_cli' => ini_get('opcache.enable_cli'),
            'run' => getenv('BENCH_RUN') ?: 'manual',
            'sql_executed' => 0,
            'cases' => [
                'bootstrap' => BenchmarkHarness::measure(static fn() => Bootstrap::boot($root), 100, 10),
                'native_fixture20' => BenchmarkHarness::measure(
                    static fn() => $components->render($request),
                    100,
                    10,
                ),
                'translator_create_translate' => BenchmarkHarness::measure(
                    static fn() => TranslatorFactory::create($root . '/translations', 'tr')
                        ->translate('core.error.unavailable.count', ['count' => 2]),
                    100,
                    10,
                ),
                'container_build_validate' => BenchmarkHarness::measure(
                    static fn() => FrontController::services(
                        $root,
                        $config,
                        InstallationState::Installed,
                        new RecordingReporter(),
                        [FrontController::STORAGE_ENVIRONMENT => $site->storagePath()],
                    ),
                    100,
                    10,
                ),
                'config_load_validate' => BenchmarkHarness::measure(
                    static fn() => ConfigLoader::load($site->configDirectory . '/local.php', ['DB_HOST' => 'db']),
                    100,
                    10,
                ),
                'log_error_with_exception' => BenchmarkHarness::measure(
                    static fn() => $logger->error('unhandled_exception', [
                        'incident' => '0123456789abcdef',
                        'exception' => $exception,
                        'client_ip' => '203.0.113.9',
                    ]),
                    100,
                    10,
                ),
                'cache_get_hit' => BenchmarkHarness::measure(static fn() => $cache->get('hit'), 100, 10),
                'cache_set' => BenchmarkHarness::measure(static fn() => $cache->set('set', $cached, 60), 100, 10),
                'csrf_token_validate' => BenchmarkHarness::measure(
                    static fn() => $csrf->isValid($csrf->token()),
                    100,
                    10,
                ),
                'template_render_warm' => BenchmarkHarness::measure(
                    static fn() => $views->render('layouts/page.twig', $page),
                    100,
                    10,
                ),
                'password_hash_argon2id' => BenchmarkHarness::measure(
                    static fn() => $argon2->hash('correct horse battery staple'),
                    20,
                    2,
                ),
                'password_verify_argon2id' => BenchmarkHarness::measure(
                    static fn() => $argon2->verify('correct horse battery staple', $argon2Hash),
                    20,
                    2,
                ),
                'password_hash_bcrypt11' => BenchmarkHarness::measure(
                    static fn() => $bcrypt->hash('correct horse battery staple'),
                    10,
                    1,
                ),
                // M02.09: 50 forums x 20 permissions, a fresh subject (no memo) per iteration.
                'permissions_50x20' => BenchmarkHarness::measure(
                    static function () use ($permissionResolver, $permissionKeys): void {
                        $subject = (clone $permissionResolver)->user(1, [2, 3, 100]);

                        foreach (range(1, 50) as $forumId) {
                            foreach ($permissionKeys as $key) {
                                $subject->value($key, $forumId);
                            }
                        }
                    },
                    50,
                    5,
                ),
                // M03.13: parse and render an 8 KB post with every tag type.
                'bbcode_render_8kb' => BenchmarkHarness::measure(static fn() => $bbcode->render($post8kb), 100, 10),
                'front_controller_maintenance' => BenchmarkHarness::measure(
                    static fn() => $maintenance->respond(Request::create('/forums')),
                    50,
                    5,
                ),
                'front_controller_home' => BenchmarkHarness::measure(
                    static fn() => $site->respond(Request::create('/robots.txt')),
                    100,
                    10,
                ),
            ],
        ];

        $site->remove();
        $logs->remove();
        $cacheStorage->remove();
        self::writeReport($report, getenv('BENCH_OUTPUT_DIR') ?: $root . '/build/benchmarks');
        fwrite(STDERR, json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    private static function post8kb(): string
    {
        $block = "[quote=Ay\u{015F}e][b]Merhaba[/b] [i]d\u{00FC}nya[/i] \u{1F44B} "
            . "https://example.com/page?x=1[/quote]\n"
            . "[list][*][url=https://example.com]link[/url][*][color=red]red[/color] [size=large]big[/size][/list]\n"
            . "[code=php]<?php echo \"hi\"; ?>[/code][spoiler=Plot]secret[/spoiler]\n"
            . "[table][tr][th]a[/th][th]b[/th][/tr][tr][td]1[/td][td]2[/td][/tr][/table]"
            . "[img]https://example.com/a.png[/img] plain text with <html> & \"quotes\".\n";

        return substr(str_repeat($block, (int) ceil(8192 / strlen($block))), 0, 8192);
    }

    /**
     * 50 forums (10 roots with 4 children each), rules on every root for three groups, and 20 keys.
     *
     * @return array{0: PermissionResolver, 1: list<string>}
     */
    private static function permissionFixture(): array
    {
        $catalog = PermissionCatalog::core();
        $store = new MemoryRuleStore($catalog);
        $parents = [];

        foreach (range(1, 50) as $forumId) {
            $parents[$forumId] = $forumId % 5 === 1 ? null : $forumId - (($forumId - 1) % 5);
        }

        foreach (array_keys(array_filter($parents, static fn(?int $parent): bool => $parent === null)) as $root) {
            $store->setGroupRule(2, 'forum.view', $root % 2 === 1, $root);
            $store->setGroupRule(3, 'forum.view', true, $root);
            $store->setGroupRule(100, 'forum.post.edit_window_minutes', $root * 10, $root);
        }

        $store->setGroupRule(2, 'forum.post.reply', true);
        $store->setGroupRule(100, 'forum.post.reply', 'never');
        $keys = array_keys($catalog->all());
        $keys[] = 'forum.extension.example';

        return [new PermissionResolver($catalog, $store, new ArrayForumTree($parents)), $keys];
    }

    /**
     * @param array<string, mixed> $report
     */
    private static function writeReport(array $report, string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $directory . '/benchmark-' . $report['run'] . '.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }
}
