<?php

declare(strict_types=1);

namespace Nolviko\Core\Locale;

use InvalidArgumentException;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\Translator;

/**
 * Build a MessageTranslator from `translations/<locale>/<domain>.php` catalogs with explicit English fallback.
 *
 * Each catalog file returns an array of ICU messages whose IDs start with `<domain>.`.
 */
final class TranslatorFactory
{
    public const FALLBACK_LOCALE = 'en';

    private const LOCALE_PATTERN = '/^[a-z]{2,3}(?:_[A-Z]{2})?$/D';

    private const DOMAIN_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    private const LOADER = 'php';

    /**
     * @throws InvalidArgumentException When the directory has no English catalog.
     */
    public static function create(string $translationsDirectory, string $requestedLocale): MessageTranslator
    {
        $supported = self::supportedLocales($translationsDirectory);

        if (!in_array(self::FALLBACK_LOCALE, $supported, true)) {
            throw new InvalidArgumentException('translation_fallback_catalog_missing');
        }

        $locale = in_array($requestedLocale, $supported, true) ? $requestedLocale : self::FALLBACK_LOCALE;
        $files = [];

        // 2026-11-21: domains load on first use (CatalogTranslator) instead of a full Symfony catalogue per request.
        foreach (array_unique([self::FALLBACK_LOCALE, $locale]) as $catalogLocale) {
            foreach (self::catalogFiles($translationsDirectory . '/' . $catalogLocale) as $file) {
                $files[$catalogLocale][basename($file, '.php')] = $file;
            }
        }

        return new CatalogTranslator($locale, $files);
    }

    /**
     * The previous Symfony-catalogue translator, kept for comparison tests.
     */
    public static function createSymfony(string $translationsDirectory, string $requestedLocale): MessageTranslator
    {
        $supported = self::supportedLocales($translationsDirectory);
        $locale = in_array($requestedLocale, $supported, true) ? $requestedLocale : self::FALLBACK_LOCALE;
        $translator = new Translator($locale);
        $translator->setFallbackLocales([self::FALLBACK_LOCALE]);
        $translator->addLoader(self::LOADER, new PhpFileLoader());

        foreach (array_unique([self::FALLBACK_LOCALE, $locale]) as $catalogLocale) {
            self::addCatalogs($translator, $translationsDirectory, $catalogLocale);
        }

        return new SymfonyMessageTranslator($translator);
    }

    /**
     * Locale directories with a valid code and at least one catalog file, sorted.
     *
     * @return list<string>
     */
    public static function supportedLocales(string $translationsDirectory): array
    {
        $locales = [];

        foreach (glob($translationsDirectory . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $locale = basename($directory);

            if (preg_match(self::LOCALE_PATTERN, $locale) === 1 && self::catalogFiles($directory) !== []) {
                $locales[] = $locale;
            }
        }

        sort($locales);

        return $locales;
    }

    /**
     * @return list<string> Catalog files whose names are valid domains.
     */
    public static function catalogFiles(string $localeDirectory): array
    {
        $files = [];

        foreach (glob($localeDirectory . '/*.php') ?: [] as $file) {
            if (preg_match(self::DOMAIN_PATTERN, basename($file, '.php')) === 1) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }

    private static function addCatalogs(Translator $translator, string $translationsDirectory, string $locale): void
    {
        foreach (self::catalogFiles($translationsDirectory . '/' . $locale) as $file) {
            $domain = basename($file, '.php') . MessageCatalogueInterface::INTL_DOMAIN_SUFFIX;
            $translator->addResource(self::LOADER, $file, $locale, $domain);
        }
    }
}
