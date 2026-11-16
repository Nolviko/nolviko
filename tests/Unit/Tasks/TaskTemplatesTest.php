<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Tasks;

use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Routing\RouterFactory;
use Nolviko\Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;

/** Both task/cache screens render escaped, localized empty/error and populated states without JavaScript. */
final class TaskTemplatesTest extends TestCase
{
    public function testBothScreensRenderInBothLocales(): void
    {
        $root = dirname(__DIR__, 3);
        $hostile = '<script>alert(1)</script>';

        foreach (['en', 'tr'] as $locale) {
            $views = TwigFactory::create(
                $root,
                '/missing-task-cache',
                TranslatorFactory::create($root . '/translations', $locale),
                urls: RouterFactory::create($root, '/missing-task-routes')
            );

            foreach ([true, false] as $mode) {
                $html = $views->render('admin/tasks.twig', ['title' => 'test', 'nav' => [], 'cache_mode' => $mode,
                    'items' => [['id' => 1, 'event_type' => $hostile, 'status' => 'failed', 'attempts' => 8]],
                    'summary' => ['failed' => 1, 'expired' => 0, 'overdue' => 1, 'capped' => false],
                    'filter' => '', 'redis' => ['enabled' => false, 'reason' => null], 'cli_enabled' => false,
                    'error' => null, 'notice' => null, 'action' => '/admin/tasks', 'older' => null,
                    'csrf_field' => '_csrf', 'csrf_token' => 'fixture']);
                self::assertStringNotContainsString($hostile, $html);
                self::assertStringNotContainsString('>tasks.', $html);
                self::assertStringContainsString('<form', $html);
            }
        }
    }
}
