<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Where to go after signing in (beta.10: a member whose session ended while replying comes back to the same reply
 * page). Only a path on this site is accepted: it starts with the site's base path, never with `//` or a scheme,
 * has no backslash, whitespace or control characters, and never points back to the sign-in pages.
 */
final class ReturnPath
{
    public const FIELD = 'return';

    public static function of(Request $request): ?string
    {
        // Carried in the form address (?return=), so the sign-in templates need no extra field.
        $value = $request->query->all()[self::FIELD] ?? $request->request->all()[self::FIELD] ?? null;

        return is_string($value) ? self::safe($value, $request->getBasePath()) : null;
    }

    public static function safe(string $value, string $basePath = ''): ?string
    {
        $base = rtrim($basePath, '/');
        if (
            strlen($value) > 500 || !str_starts_with($value, $base . '/') || str_starts_with($value, '//')
            || preg_match('~[\\\\\s\x00-\x1f\x7f]~', $value) === 1
            || preg_match('~^' . preg_quote($base, '~') . '/(login|logout|register)(/|\?|$)~', $value) === 1
        ) {
            return null;
        }

        return $value;
    }

    /** The URL with `?return=` appended when there is a safe return path. */
    public static function append(string $url, ?string $return): string
    {
        return $return === null ? $url : $url . (str_contains($url, '?') ? '&' : '?') . self::FIELD . '='
            . rawurlencode($return);
    }
}
