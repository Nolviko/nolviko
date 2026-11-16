<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use Normalizer;

/**
 * The comparison forms that decide whether two usernames or two email addresses are "the same" (DATABASE.md:
 * uniqueness is enforced on these columns, never by database collation).
 *
 * Username: Unicode NFKC, then full case folding (locale-independent), then NFKC again, then "i" + combining
 * dot above (U+0069 U+0307, what case folding makes of Turkish "İ") becomes plain "i". So "Ａｄｍｉｎ", "ADMIN"
 * and "admin" collide, and Turkish "İSMAİL" equals "ismail", while dotless "ı" stays a different letter
 * ("ılık" is not "ilik"). Emoji and other symbols are kept as they are (whether they are allowed is a
 * registration rule). Email: surrounding whitespace removed and the whole address lower-cased (the local part
 * is treated as case-insensitive, as every major provider does).
 */
final class IdentityNormalizer
{
    private const COMBINING_DOT_ABOVE = "i\u{0307}";

    public static function username(string $username): string
    {
        $text = self::nfkc(trim($username));
        $folded = self::nfkc(mb_convert_case($text, MB_CASE_FOLD, 'UTF-8'));

        return str_replace(self::COMBINING_DOT_ABOVE, 'i', $folded);
    }

    public static function email(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    private static function nfkc(string $text): string
    {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_KC);

        return $normalized === false ? $text : $normalized;
    }
}
