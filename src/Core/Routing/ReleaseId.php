<?php

declare(strict_types=1);

namespace Nolviko\Core\Routing;

use UnexpectedValueException;

/**
 * Identifies the deployed code, so release-scoped caches are rebuilt exactly when the release changes.
 *
 * A packaged release carries `config/release.php` (written by tools/package.php). A development checkout has
 * none; there the ID is a hash of the route definitions, so editing a route invalidates the cache too.
 */
final class ReleaseId
{
    public const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';

    private const DEVELOPMENT_PREFIX = 'dev-';

    /**
     * @throws UnexpectedValueException When the release file exists but does not return a valid ID.
     */
    public static function fromFile(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $releaseId = (static fn(string $path): mixed => require $path)($file);

        if (!is_string($releaseId) || preg_match(self::PATTERN, $releaseId) !== 1) {
            throw new UnexpectedValueException('release_id_invalid');
        }

        return $releaseId;
    }

    /**
     * @param list<RouteDefinition> $definitions
     */
    public static function development(array $definitions): string
    {
        return self::DEVELOPMENT_PREFIX . hash('xxh128', serialize($definitions));
    }
}
