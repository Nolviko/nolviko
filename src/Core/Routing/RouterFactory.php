<?php

declare(strict_types=1);

namespace Nolviko\Core\Routing;

use Nolviko\Core\Extensions\ExtensionRegistry;

/**
 * Builds the router from the core routes, extension routes and (since 2026-11-15) extension address patterns,
 * with the compiled tables cached per release and route set.
 */
final class RouterFactory
{
    public static function create(string $root, string $storageDirectory, ?ExtensionRegistry $extensions = null): Router
    {
        $patterns = RoutePatterns::apply(CoreRoutes::definitions(), $extensions?->routePatterns ?? []);
        $definitions = [...$patterns['definitions'], ...($extensions?->definitions() ?? [])];
        $releaseId = ReleaseId::fromFile($root . '/config/release.php') ?? ReleaseId::development($definitions);
        if ($extensions !== null && ($extensions->routes !== [] || $extensions->routePatterns !== [])) {
            $releaseId = 'ext-' . hash('xxh128', $releaseId . serialize($definitions));
        }
        $cache = new RouteCache(is_dir($storageDirectory) ? $storageDirectory . '/cache/routes' : null);

        return new Router($cache->load($releaseId, $definitions), dropped: $patterns['dropped']);
    }
}
