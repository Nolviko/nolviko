<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Admin;

use DOMDocument;
use DOMXPath;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\NavItemView;
use PHPUnit\Framework\TestCase;

/** Server-rendered fallback and escaping remain independent of optional JavaScript. */
final class AdminCommandsTest extends TestCase
{
    public function testFallbackContainsEveryLinkWithHiddenEnhancementControls(): void
    {
        $app = dirname(__DIR__, 3);
        $translator = TranslatorFactory::create($app . '/translations', 'tr');
        $html = TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-admin-tests', $translator)->render(
            'admin/commands.twig',
            ['nav' => [new NavItemView('<script>unsafe</script>', '/admin/members', false),
                new NavItemView('İzinler', '/admin/permissions', false)]],
        );
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        self::assertSame(2, $xpath->query('//details/ul/li/a')->length);
        self::assertSame(0, $xpath->query('//details/ul/li[@hidden]')->length);
        self::assertSame(1, $xpath->query('//*[@data-command-tools and @hidden]')->length);
        self::assertSame(1, $xpath->query('//*[@role="status"]')->length);
        self::assertStringContainsString('Yönetim sayfası ara', $html);
        self::assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testUnsafeCommandDestinationIsRejected(): void
    {
        $app = dirname(__DIR__, 3);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $html = TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-admin-tests', $translator)->render(
            'admin/commands.twig',
            ['nav' => [new NavItemView('Unsafe', 'javascript:alert(1)', false)]],
        );
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('href="#"', $html);
    }
}
