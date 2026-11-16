<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Locale;

use Nolviko\Core\Config\ConfigException;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Locale\LocaleContext;
use Nolviko\Core\Locale\LocaleDirection;
use Nolviko\Core\Locale\LocaleResolver;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\RequestTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Tests\Support\SiteFixture;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * M02.11: user preference → site default → Accept-Language → en, validated against installed catalogs, with
 * `<html lang dir>` following the result and malformed input falling back safely.
 */
final class LocaleResolutionTest extends TestCase
{
    private const INSTALLED = ['ar', 'en', 'pt_BR', 'tr'];

    /**
     * @return array<string, array{0: string|null, 1: string|null, 2: string|null, 3: string}>
     */
    public static function orders(): array
    {
        return [
            'user preference first' => ['tr', 'ar', 'pt-BR', 'tr'],
            'site default next' => [null, 'ar', 'tr', 'ar'],
            'header next' => [null, null, 'tr-TR,en;q=0.5', 'tr'],
            'en last' => [null, null, null, 'en'],
            'unsupported preference is skipped' => ['de', null, 'tr', 'tr'],
            'malformed preference is skipped' => ['../../etc', 'xx-invalid-!', 'ar', 'ar'],
            'region kept when installed' => [null, null, 'pt-br', 'pt_BR'],
            'region falls back to language' => ['tr_CY', null, null, 'tr'],
            'language does not pick a region' => [null, null, 'pt', 'en'],
            'nothing installed matches' => ['de', 'fr', 'ja,zh;q=0.9', 'en'],
        ];
    }

    #[DataProvider('orders')]
    public function testResolutionOrder(?string $user, ?string $site, ?string $header, string $expected): void
    {
        self::assertSame($expected, (new LocaleResolver(self::INSTALLED))->resolve($user, $site, $header));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function headers(): array
    {
        return [
            'quality order' => ['en;q=0.3, tr;q=0.9, ar', ['ar', 'tr', 'en']],
            'equal quality keeps order' => ['tr, en, ar', ['tr', 'en', 'ar']],
            'zero quality is refused' => ['tr;q=0, en', ['en']],
            'wildcard is ignored' => ['*, tr;q=0.1', ['tr']],
            'bad quality drops the entry' => ['tr;q=abc, en;q=2, ar;q=1.0', ['ar']],
            'garbage' => [";;,,=,\u{00FC}ber, en", ['en']],
            'scripts and variants' => ['zh-Hant-TW, sr-Latn', ['zh-Hant-TW', 'sr-Latn']],
            'empty' => ['', []],
            'overlong header' => [str_repeat('en,', 400), []],
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('headers')]
    public function testAcceptLanguageParsing(string $header, array $expected): void
    {
        self::assertSame($expected, LocaleResolver::acceptLanguage($header));
    }

    public function testDirection(): void
    {
        self::assertSame('rtl', LocaleDirection::of('ar'));
        self::assertSame('rtl', LocaleDirection::of('he-IL'));
        self::assertSame('rtl', LocaleDirection::of('fa_IR'));
        self::assertSame('ltr', LocaleDirection::of('tr'));
        self::assertSame('ltr', LocaleDirection::of('en'));
        self::assertSame('pt-BR', LocaleResolver::htmlTag('pt_BR'));
    }

    public function testFailingSourcesFallBackAndTheRequestDecides(): void
    {
        $context = new LocaleContext(
            new LocaleResolver(self::INSTALLED),
            static fn(): ?string => throw new RuntimeException('database down'),
            static fn(): ?string => null,
        );

        self::assertSame('en', $context->locale(), 'before resolution');
        self::assertSame('ar', $context->resolve(Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ar'])));
        self::assertSame('ar', $context->locale());
    }

    public function testPagesAndErrorPagesCarryLangAndDir(): void
    {
        $catalogs = new TemporaryDirectory();
        $app = dirname(__DIR__, 3);

        foreach (['en', 'ar'] as $locale) {
            foreach (glob($app . '/translations/en/*.php') ?: [] as $file) {
                $catalogs->write($locale . '/' . basename($file), (string) file_get_contents($file));
            }
        }

        $context = new LocaleContext(
            new LocaleResolver(TranslatorFactory::supportedLocales($catalogs->path)),
            static fn(): ?string => null,
            static fn(): ?string => null,
        );
        $translator = new RequestTranslator(
            static fn(): MessageTranslator => TranslatorFactory::create($catalogs->path, $context->locale()),
        );
        $context->resolve(Request::create('/', server: ['HTTP_ACCEPT_LANGUAGE' => 'ar-EG, en;q=0.5']));

        $page = TwigFactory::create($app, $catalogs->path . '/no-storage', $translator)
            ->render('layouts/page.twig', ['title' => 'x']);
        $error = (string) (new ErrorResponder($translator))->notFound()->getContent();
        $catalogs->remove();

        self::assertStringContainsString('<html lang="ar" dir="rtl" data-appearance="system">', $page);
        self::assertStringContainsString('<html lang="ar" dir="rtl">', $error);
    }

    public function testSiteDefaultIsValidatedConfiguration(): void
    {
        $settings = SiteFixture::validConfig();
        $settings['locale'] = ['default' => 'tr'];

        self::assertSame('tr', ConfigLoader::fromArray($settings)->defaultLocale);
        self::assertNull(ConfigLoader::fromArray(SiteFixture::validConfig())->defaultLocale);

        $settings['locale'] = ['default' => 'Turkish'];

        try {
            ConfigLoader::fromArray($settings);
            self::fail('accepted an invalid locale');
        } catch (ConfigException $failure) {
            self::assertSame(['config_value_invalid:locale.default'], $failure->diagnostics());
        }
    }
}
