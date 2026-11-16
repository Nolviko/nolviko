<?php

declare(strict_types=1);

namespace Nolviko\Core\Locale;

/**
 * Picks the page locale from the installed catalogs (M02.11): user preference, then site default, then the
 * browser's `Accept-Language`, then `en`. Every candidate is validated against the installed locales; a
 * region falls back to its language (`tr-TR` → `tr`), and a malformed header is ignored, never an error.
 *
 * Locales use the catalog form `ll` or `ll_RR`; `htmlTag()` gives the BCP 47 form for `<html lang>`.
 */
final class LocaleResolver
{
    /** Longer headers are not parsed at all (no legitimate browser sends one). */
    private const MAX_HEADER_LENGTH = 1000;

    private const MAX_RANGES = 20;

    private const RANGE_PATTERN = '/^([A-Za-z]{2,3})(?:[-_]([A-Za-z]{2}))?(?:[-_][A-Za-z0-9]{1,8})*$/D';

    private const QUALITY_PATTERN = '/^q=(0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)$/Di';

    /**
     * @param list<string> $installed Catalog locales (TranslatorFactory::supportedLocales()).
     */
    public function __construct(
        private readonly array $installed,
    ) {
    }

    public function resolve(?string $userPreference, ?string $siteDefault, ?string $acceptLanguage): string
    {
        foreach ([$userPreference, $siteDefault] as $candidate) {
            $match = $candidate === null ? null : $this->match($candidate);

            if ($match !== null) {
                return $match;
            }
        }

        foreach (self::acceptLanguage((string) $acceptLanguage) as $range) {
            $match = $this->match($range);

            if ($match !== null) {
                return $match;
            }
        }

        return TranslatorFactory::FALLBACK_LOCALE;
    }

    /**
     * Language ranges of an `Accept-Language` header, most preferred first (equal weights keep their order);
     * `q=0`, `*` and malformed entries are dropped.
     *
     * @return list<string>
     */
    public static function acceptLanguage(string $header): array
    {
        if ($header === '' || strlen($header) > self::MAX_HEADER_LENGTH) {
            return [];
        }

        $ranges = [];

        foreach (array_slice(explode(',', $header), 0, self::MAX_RANGES) as $position => $entry) {
            $parts = array_map(trim(...), explode(';', $entry));
            $quality = 1.0;

            if (isset($parts[1])) {
                if (preg_match(self::QUALITY_PATTERN, $parts[1], $q) !== 1) {
                    continue;
                }

                $quality = (float) $q[1];
            }

            if ($quality > 0 && preg_match(self::RANGE_PATTERN, $parts[0]) === 1) {
                $ranges[] = [$parts[0], $quality, $position];
            }
        }

        usort($ranges, static fn(array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        return array_column($ranges, 0);
    }

    /**
     * `tr_TR` becomes `tr-TR`.
     */
    public static function htmlTag(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }

    private function match(string $candidate): ?string
    {
        if (preg_match(self::RANGE_PATTERN, trim($candidate), $parts) !== 1) {
            return null;
        }

        $language = strtolower($parts[1]);
        $withRegion = isset($parts[2]) ? $language . '_' . strtoupper($parts[2]) : null;

        foreach ([$withRegion, $language] as $locale) {
            if ($locale !== null && in_array($locale, $this->installed, true)) {
                return $locale;
            }
        }

        return null;
    }
}
