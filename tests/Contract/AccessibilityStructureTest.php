<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use DOMDocument;
use DOMXPath;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\View\TwigFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Rendered semantic RTL contract; visual layout, assistive technology and WCAG audits remain separate gates. */
final class AccessibilityStructureTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function layouts(): array
    {
        return ['public' => ['layouts/page.twig', 'main-content'], 'admin' => ['admin/layout.twig', 'admin-main']];
    }

    #[DataProvider('layouts')]
    public function testRtlSkipLinkHasOneFocusableTargetAndOneMainLandmark(string $template, string $target): void
    {
        $app = dirname(__DIR__, 2);
        $english = TranslatorFactory::create($app . '/translations', 'en');
        $translator = $this->createStub(MessageTranslator::class);
        $translator->method('locale')->willReturn('ar');
        $translator->method('translate')->willReturnCallback($english->translate(...));
        $html = TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-no-storage', $translator)->render(
            $template,
            ['title' => 'اختبار Caner123 <unsafe>', 'nav' => []],
        );
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        self::assertSame('ar', $dom->documentElement->getAttribute('lang'));
        self::assertSame('rtl', $dom->documentElement->getAttribute('dir'));
        self::assertSame(1, $xpath->query('//main')->length);
        self::assertSame(1, $xpath->query('//a[@class="skip-link"]')->length);
        self::assertSame('#' . $target, $xpath->evaluate('string(//a[@class="skip-link"]/@href)'));
        self::assertSame(1, $xpath->query('//*[@id="' . $target . '"]')->length);
        self::assertSame('-1', $xpath->evaluate('string(//*[@id="' . $target . '"]/@tabindex)'));
        self::assertSame(1, $xpath->query('//link[contains(@href,"/assets/accessibility.css")]')->length);
        self::assertSame(0, $xpath->query('//unsafe')->length, 'mixed-direction text must remain escaped');
    }

    public function testStandaloneForumHeadingDoesNotSkipTheSecondLevel(): void
    {
        $app = dirname(__DIR__, 2);
        $translator = TranslatorFactory::create($app . '/translations', 'en');
        $row = ['id' => 1, 'category' => false, 'title' => 'Standalone forum', 'depth' => 0,
            'url' => '/forums/1', 'threadCount' => 0, 'postCount' => 0, 'lastThreadTitle' => null,
            'lastPostAt' => null, 'newThreadUrl' => null];
        $html = TwigFactory::create($app, sys_get_temp_dir() . '/nolviko-no-storage', $translator)
            ->render('forums/index.twig', ['title' => 'Forums', 'forums' => [$row]]);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        self::assertSame(1, $xpath->query('//main//h1')->length);
        self::assertSame(1, $xpath->query('//h2/a[@href="/forums/1"]')->length);
        self::assertSame(0, $xpath->query('//h3')->length);
    }
}
