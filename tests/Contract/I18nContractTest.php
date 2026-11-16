<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use MessageFormatter;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Tools\I18n\CatalogParity;
use Nolviko\Tools\I18n\LiteralScanner;
use Nolviko\Tools\I18n\TranslationKeyScanner;
use PHPUnit\Framework\TestCase;

/**
 * Build gate for the AGENTS.md i18n rule and the ADR-008 catalog contract (M01.01c).
 */
final class I18nContractTest extends TestCase
{
    /** Diagnostic IDs from config/runtime-policy.php, docker/check-runtime.php and the config loader. */
    private const RUNTIME_DIAGNOSTICS = [
        'php_version_unsupported',
        'database_driver_unsupported',
        'extension_missing',
        'memory_limit_invalid',
        'memory_limit_below_128M',
        'storage_not_writable',
        'config_missing',
        'config_file_invalid',
        'config_key_unknown',
        'config_key_missing',
        'config_type_invalid',
        'config_value_invalid',
    ];

    public function testRuntimeCodeHasNoUntranslatedUserVisibleText(): void
    {
        $findings = (new LiteralScanner())->scan(self::runtimeSourceDirectories());

        self::assertSame([], array_map('strval', $findings), 'Use translation keys for user-visible text.');
    }

    public function testEveryUsedMessageIdExistsInEnglish(): void
    {
        $translator = TranslatorFactory::create(self::catalogs(), TranslatorFactory::FALLBACK_LOCALE);
        $missing = (new TranslationKeyScanner())->missing(self::runtimeSourceDirectories(), $translator);

        self::assertSame([], array_map('strval', $missing), 'Add the missing IDs to translations/en/.');
    }

    public function testCatalogsAreNamespacedPlainTextIcuMessages(): void
    {
        $checked = 0;

        foreach (TranslatorFactory::supportedLocales(self::catalogs()) as $locale) {
            foreach (TranslatorFactory::catalogFiles(self::catalogs() . '/' . $locale) as $file) {
                $domain = basename($file, '.php');

                foreach (require $file as $id => $message) {
                    $label = $locale . '/' . $domain . ': ' . $id;
                    self::assertStringStartsWith($domain . '.', $id, $label);
                    self::assertIsString($message, $label);
                    self::assertStringNotContainsString('<', $message, $label . ' must not contain HTML');
                    self::assertNotNull(MessageFormatter::create($locale, $message), $label . ' is not valid ICU');
                    ++$checked;
                }
            }
        }

        self::assertGreaterThan(0, $checked);
    }

    public function testRuntimeDiagnosticsHaveEnglishTexts(): void
    {
        $translator = TranslatorFactory::create(self::catalogs(), TranslatorFactory::FALLBACK_LOCALE);

        foreach (self::RUNTIME_DIAGNOSTICS as $diagnostic) {
            self::assertTrue($translator->has('diagnostics.' . $diagnostic), $diagnostic);
        }
    }

    public function testTurkishReferenceKeysAndArgumentTypesMatchEnglish(): void
    {
        $reference = [];
        $translated = [];

        foreach (TranslatorFactory::catalogFiles(self::catalogs() . '/en') as $file) {
            $reference = array_replace($reference, require $file);
        }

        foreach (TranslatorFactory::catalogFiles(self::catalogs() . '/tr') as $file) {
            $translated = array_replace($translated, require $file);
        }

        self::assertSame([], (new CatalogParity())->compare($reference, $translated, 'tr'));
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function catalogs(): string
    {
        return self::root() . '/translations';
    }

    /**
     * Runtime PHP and the template locations planned in THEMES.md; absent directories are skipped.
     *
     * @return list<string>
     */
    private static function runtimeSourceDirectories(): array
    {
        return [
            self::root() . '/src',
            self::root() . '/views',
            self::root() . '/themes',
        ];
    }
}
