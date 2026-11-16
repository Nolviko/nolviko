<?php

declare(strict_types=1);

namespace Nolviko\Core\Locale;

/**
 * Text direction for `<html dir>`: right-to-left for the languages written in Arabic or Hebrew script.
 */
final class LocaleDirection
{
    public const LTR = 'ltr';

    public const RTL = 'rtl';

    private const RTL_LANGUAGES = ['ar', 'ckb', 'dv', 'fa', 'he', 'ps', 'sd', 'ug', 'ur', 'yi'];

    /**
     * @param string $locale Catalog (`ar_EG`) or BCP 47 (`ar-EG`) form.
     */
    public static function of(string $locale): string
    {
        $language = strtolower((string) preg_replace('/[-_].*$/s', '', $locale));

        return in_array($language, self::RTL_LANGUAGES, true) ? self::RTL : self::LTR;
    }
}
