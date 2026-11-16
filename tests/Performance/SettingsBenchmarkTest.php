<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\SettingsCatalog;
use Nolviko\Modules\Admin\Http\SettingsRegistryHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Configuration search and its bounded SSR page do not create a database connection. */
final class SettingsBenchmarkTest extends TestCase
{
    public function testCatalogSearchAndPageRender(): void
    {
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $router = new Router(CompiledRoutes::compile(CoreRoutes::definitions()));
        $config = new Config(
            'mysql',
            'localhost',
            null,
            'fixture',
            'fixture',
            new Secret('fixture'),
            new Secret(str_repeat('s', 32))
        );
        $catalog = new SettingsCatalog($config, $translator, $router);
        $handler = new SettingsRegistryHandler(
            $catalog,
            $translator,
            TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-settings-bench', $translator),
            $router
        );
        $request = Request::create('/admin/settings');
        self::assertCount(1, $catalog->search('registration IP'));
        self::assertSame(200, $handler->handle($request, [])->getStatusCode());
        $cases = [
            'settings_search' => BenchmarkHarness::measure(static fn(): array => $catalog->search('registration IP')),
            'settings_render' => BenchmarkHarness::measure(static fn(): Response => $handler->handle($request, [])),
        ];
        fwrite(STDERR, 'settings_benchmark ' . json_encode($cases, JSON_THROW_ON_ERROR) . "\n");
        $output = getenv('BENCH_OUTPUT_DIR');

        if (is_string($output) && $output !== '') {
            if (!is_dir($output)) {
                mkdir($output, 0755, true);
            }

            file_put_contents($output . '/settings.json', json_encode(['cases' => $cases], JSON_THROW_ON_ERROR));
        }
    }
}
