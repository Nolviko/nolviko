<?php

declare(strict_types=1);

namespace Nolviko\Tests\Performance;

use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\CompiledRoutes;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\AdminNavigation;
use PHPUnit\Framework\TestCase;

/** Server-only command links require no additional database query. */
final class AdminCommandsBenchmarkTest extends TestCase
{
    public function testWarmCommandPanelRender(): void
    {
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $views = TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-command-bench', $translator);
        $nav = AdminNavigation::items(new Router(CompiledRoutes::compile(CoreRoutes::definitions())), $translator, '');
        $operation = static fn(): string => $views->render('admin/commands.twig', ['nav' => $nav]);
        self::assertStringContainsString('data-admin-commands', $operation());
        $report = BenchmarkHarness::measure($operation, 100, 10);
        fwrite(STDERR, 'admin_commands_render ' . json_encode($report, JSON_THROW_ON_ERROR) . "\n");
    }
}
