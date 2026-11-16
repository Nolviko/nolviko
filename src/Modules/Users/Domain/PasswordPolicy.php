<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use Normalizer;
use SensitiveParameter;

/**
 * What a new password must satisfy (NIST SP 800-63B style): at least MIN_LENGTH and at most MAX_LENGTH
 * characters, not on the bundled list of common passwords, and not the account's own username or email.
 * No composition rules (no "one digit, one symbol"). Length is counted in Unicode characters after NFC, and
 * the maximum is enforced, never truncated.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    public const MAX_LENGTH = 256;

    public const COMMON_LIST = __DIR__ . '/common-passwords.txt';

    /** @var array<string, true>|null */
    private static ?array $common = null;

    /**
     * @param list<string> $personal Username, email and similar values the password must not equal.
     *
     * @return list<string> Diagnostics (`password_too_short`, `password_too_long`, `password_too_common`,
     *     `password_matches_account`); empty when acceptable.
     */
    public static function check(#[SensitiveParameter] string $password, array $personal = []): array
    {
        $normalized = Normalizer::normalize($password, Normalizer::FORM_C);
        $length = mb_strlen($normalized === false ? $password : $normalized, 'UTF-8');

        if ($length < self::MIN_LENGTH) {
            return ['password_too_short'];
        }

        if ($length > self::MAX_LENGTH) {
            return ['password_too_long'];
        }

        $folded = mb_strtolower($password, 'UTF-8');

        if (isset(self::common()[$folded])) {
            return ['password_too_common'];
        }

        foreach ($personal as $value) {
            $value = mb_strtolower(trim($value), 'UTF-8');

            if ($value !== '' && ($folded === $value || $folded === strstr($value, '@', true))) {
                return ['password_matches_account'];
            }
        }

        return [];
    }

    /**
     * @return array<string, true>
     */
    private static function common(): array
    {
        if (self::$common === null) {
            $lines = file(self::COMMON_LIST, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $words = array_filter($lines, static fn(string $line): bool => !str_starts_with($line, '#'));
            self::$common = array_fill_keys(array_map(static fn(string $w): string => mb_strtolower($w), $words), true);
        }

        return self::$common;
    }
}
