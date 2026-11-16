<?php

declare(strict_types=1);

namespace Nolviko\Core\Routing;

/**
 * Request-path rules applied before matching (ADR-005, carried from the M00.04 contract).
 *
 * The raw path is decoded exactly once, by the matcher. Encoded slash, backslash, NUL or percent, malformed
 * escapes, invalid UTF-8, control characters, empty or dot segments and over-long paths are rejected.
 */
final class PathPolicy
{
    public const MAX_LENGTH = 2048;

    private const RAW_REJECT_PATTERN = '/%(?![a-f0-9]{2})|%(?:2f|5c|00|25)/i';

    private const DECODED_REJECT_PATTERN = '~[\\\\\x00-\x20\x7f]|//|(?:^|/)\.{1,2}(?:/|$)~';

    /**
     * @throws MalformedPathException
     */
    public static function assertWellFormed(string $rawPath): void
    {
        if (strlen($rawPath) > self::MAX_LENGTH || preg_match(self::RAW_REJECT_PATTERN, $rawPath) === 1) {
            throw new MalformedPathException();
        }

        $path = rawurldecode($rawPath);

        if (!mb_check_encoding($path, 'UTF-8') || preg_match(self::DECODED_REJECT_PATTERN, $path) === 1) {
            throw new MalformedPathException();
        }
    }

    /**
     * The one canonical form of a well-formed raw path: ASCII lowercase, no trailing slash except `/`.
     */
    public static function canonical(string $rawPath): string
    {
        $path = strtolower($rawPath);

        return $path !== '/' && str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
    }
}
