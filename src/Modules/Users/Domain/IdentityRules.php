<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

use Normalizer;

/**
 * What a username and an email address must look like (M02.15a; registration M02.03 reuses it). A username is
 * 3–32 characters of letters, digits, spaces and `._-` after NFC normalization and trimming, starting with a
 * letter or digit; an email address is at most 254 bytes and valid by PHP's checker (Unicode local parts
 * allowed). Uniqueness is the repository's job (IdentityTaken).
 */
final class IdentityRules
{
    public const USERNAME_MIN = 3;

    public const USERNAME_MAX = 32;

    public const EMAIL_MAX_BYTES = 254;

    private const USERNAME_PATTERN = '/^[\p{L}\p{N}](?:[\p{L}\p{N}\p{M} ._-]*[\p{L}\p{N}\p{M}])?$/u';

    /**
     * @return string|null The username to store, or null when it is not acceptable.
     */
    public static function username(string $username): ?string
    {
        if (!mb_check_encoding($username, 'UTF-8')) {
            return null;
        }

        $username = trim((string) Normalizer::normalize($username));
        $length = mb_strlen($username);

        if ($length < self::USERNAME_MIN || $length > self::USERNAME_MAX) {
            return null;
        }

        return preg_match(self::USERNAME_PATTERN, $username) === 1 && !str_contains($username, '  ')
            ? $username
            : null;
    }

    /**
     * @return string|null The address to store, or null when it is not acceptable.
     */
    public static function email(string $email): ?string
    {
        $email = trim($email);

        if ($email === '' || strlen($email) > self::EMAIL_MAX_BYTES || !mb_check_encoding($email, 'UTF-8')) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) === false ? null : $email;
    }
}
