<?php

declare(strict_types=1);

namespace Nolviko\Modules\Editor\Domain;

/**
 * ADR-012 URL allowlist for post content: absolute `http`/`https` URLs with a host, and `mailto:` with an
 * address. No relative, protocol-relative or other-scheme URL is accepted, and nothing with whitespace,
 * control characters, quotes, angle brackets or backslashes anywhere, so a URL can never break out of its
 * attribute or smuggle a scheme past the check.
 */
final class UrlPolicy
{
    public const LINK_SCHEMES = ['http', 'https', 'mailto'];

    public const IMAGE_SCHEMES = ['http', 'https'];

    private const MAX_LENGTH = 2000;

    /**
     * @param list<string> $schemes
     */
    public static function allowed(string $url, array $schemes = self::LINK_SCHEMES): bool
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7f"\'<>\\\\`]/', $url) === 1) {
            return false;
        }

        if (preg_match('~^([a-z][a-z0-9+.-]*):(.*)$~Dis', $url, $parts) !== 1) {
            return false;
        }

        $scheme = strtolower($parts[1]);

        if (!in_array($scheme, $schemes, true)) {
            return false;
        }

        return $scheme === 'mailto'
            ? preg_match('/^[^@\/?#]+@[^@\/?#]+$/D', $parts[2]) === 1
            : preg_match('~^//[^/?#@]*[a-z0-9]~i', $parts[2]) === 1;
    }
}
