<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Routing\RouteCache;
use Nolviko\Core\Routing\Router;
use Nolviko\Tests\Fixtures\ForumRoutes;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * M01.03 benchmark: 500 routes, cold cache (compile + write + load) versus warm cache (load) and match only.
 */
#[Group('benchmark')]
final class RoutingBenchmarkTest extends TestCase
{
    private const ROUTES = 500;

    private const RELEASE = 'bench';

    public function testFiveHundredRoutesColdAndWarmCache(): void
    {
        $definitions = ForumRoutes::scaled(self::ROUTES);
        $temporary = new TemporaryDirectory();
        $cache = new RouteCache($temporary->path . '/cache/routes');
        $file = (string) $cache->file(self::RELEASE);
        $request = Request::create('/section/r491/42');
        $match = static fn(Router $router): array => $router->match($request);

        try {
            self::assertCount(self::ROUTES, $definitions);
            self::assertSame('section.r491', $match(new Router($cache->load(self::RELEASE, $definitions)))['_route']);

            $cold = BenchmarkHarness::measure(
                static function () use ($cache, $definitions, $file, $match): void {
                    unlink($file);
                    $match(new Router($cache->load(self::RELEASE, $definitions)));
                },
                20,
                3,
            );
            $warm = BenchmarkHarness::measure(
                static fn() => $match(new Router($cache->load(self::RELEASE, $definitions))),
                100,
                10,
            );
            $router = new Router($cache->load(self::RELEASE, $definitions));
            $matchOnly = BenchmarkHarness::measure(static fn() => $match($router), 100, 10);
            $cacheBytes = filesize($file);
        } finally {
            $temporary->remove();
        }

        $report = [
            'profile' => 'm01-03-routing-v1',
            'php' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'opcache_cli' => ini_get('opcache.enable_cli'),
            'run' => getenv('BENCH_RUN') ?: 'manual',
            'routes' => self::ROUTES,
            'cache_bytes' => $cacheBytes,
            'sql_executed' => 0,
            'cases' => [
                'routes500_cold' => $cold,
                'routes500_warm' => $warm,
                'routes500_match' => $matchOnly,
            ],
        ];

        $directory = getenv('BENCH_OUTPUT_DIR') ?: dirname(__DIR__, 2) . '/build/benchmarks';

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $directory . '/routing-' . $report['run'] . '.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
        fwrite(STDERR, json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}
