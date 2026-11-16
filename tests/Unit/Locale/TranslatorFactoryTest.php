<?php

declare(strict_types=1);

namespace Nolviko\Tests\Unit\Locale;

use InvalidArgumentException;
use Nolviko\Core\Locale\MissingMessageException;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Locale\TwigTranslationExtension;
use Nolviko\Tests\Support\TemporaryDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class TranslatorFactoryTest extends TestCase
{
    private ?TemporaryDirectory $scratch = null;

    protected function tearDown(): void
    {
        $this->scratch?->remove();
    }

    public function testEnglishCatalogTranslates(): void
    {
        $translator = TranslatorFactory::create(self::catalogs(), 'en');

        self::assertSame('en', $translator->locale());
        self::assertSame('Something went wrong', $translator->translate('core.error.server.title'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function fallbackLocales(): array
    {
        return [
            ['zz'],
            ['de_DE'],
            ['../en'],
            ['EN'],
            [''],
        ];
    }

    #[DataProvider('fallbackLocales')]
    public function testLocaleWithoutValidCatalogFallsBackToEnglish(string $requested): void
    {
        $translator = TranslatorFactory::create(self::catalogs(), $requested);

        self::assertSame('en', $translator->locale());
        self::assertSame('Page not found', $translator->translate('core.error.not_found.title'));
    }

    public function testTurkishReferenceCatalogIsInstalled(): void
    {
        $translator = TranslatorFactory::create(self::catalogs(), 'tr');

        self::assertSame('tr', $translator->locale());
        self::assertSame('Sayfa bulunamadı', $translator->translate('core.error.not_found.title'));
    }

    public function testRealTurkishCatalogRetainsDottedLettersAndPluralArguments(): void
    {
        $translator = TranslatorFactory::create(self::catalogs(), 'tr');
        self::assertSame('Giriş yap', $translator->translate('auth.login.title'));

        foreach ([0, 1, 2] as $minutes) {
            self::assertStringContainsString(
                $minutes . ' dakika',
                $translator->translate('admin.reauth.intro', ['minutes' => $minutes])
            );
        }
    }

    public function testPartialCatalogFallsBackPerMessage(): void
    {
        $this->scratch = new TemporaryDirectory();
        $this->scratch->writeCatalog('en', 'core', ['core.a' => 'Alpha', 'core.b' => 'Beta']);
        $this->scratch->writeCatalog('tr', 'core', ['core.a' => 'Alfa']);

        $translator = TranslatorFactory::create($this->scratch->path, 'tr');

        self::assertSame('tr', $translator->locale());
        self::assertSame('Alfa', $translator->translate('core.a'));
        self::assertSame('Beta', $translator->translate('core.b'));
    }

    public function testMissingEnglishKeyIsAControlledDiagnostic(): void
    {
        $translator = TranslatorFactory::create(self::catalogs(), 'en');

        self::assertFalse($translator->has('core.does_not_exist'));
        $this->expectException(MissingMessageException::class);
        $this->expectExceptionMessage('translation_missing:core.does_not_exist');

        $translator->translate('core.does_not_exist');
    }

    public function testMessageIdWithoutDomainIsRejected(): void
    {
        $translator = TranslatorFactory::create(self::catalogs(), 'en');

        $this->expectException(MissingMessageException::class);
        $this->expectExceptionMessage('translation_id_invalid:missingdomain');

        $translator->translate('missingdomain');
    }

    public function testDirectoryWithoutEnglishCatalogFails(): void
    {
        $this->scratch = new TemporaryDirectory();
        $this->scratch->writeCatalog('tr', 'core', ['core.a' => 'Alfa']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('translation_fallback_catalog_missing');

        TranslatorFactory::create($this->scratch->path, 'tr');
    }

    public function testIcuPluralForms(): void
    {
        $translator = TranslatorFactory::create(self::catalogs(), 'en');
        $id = 'core.error.unavailable.count';

        self::assertSame('0 problems were found.', $translator->translate($id, ['count' => 0]));
        self::assertSame('1 problem was found.', $translator->translate($id, ['count' => 1]));
        self::assertSame('2 problems were found.', $translator->translate($id, ['count' => 2]));
    }

    public function testTwigEscapesTranslatedParameters(): void
    {
        $views = new Environment(
            new ArrayLoader(['page' => "{{ t('core.error.server.body', {incident: incident}) }}"]),
            ['autoescape' => 'html', 'strict_variables' => true],
        );
        $views->addExtension(new TwigTranslationExtension(TranslatorFactory::create(self::catalogs(), 'en')));

        $html = $views->render('page', ['incident' => '<script>alert(1)</script>']);

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    private static function catalogs(): string
    {
        return dirname(__DIR__, 3) . '/translations';
    }
}
