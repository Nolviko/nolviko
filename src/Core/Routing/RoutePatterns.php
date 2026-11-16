<?php

declare(strict_types=1);

namespace Nolviko\Core\Routing;

use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ExceptionInterface;

/**
 * New addresses for public pages chosen by an extension (2026-11-15, SEO extension). Only the listed routes can
 * move; a pattern starts with a word of its own (not used by any other route), names each required variable once
 * and may leave the thread slug out. The default address and the given older ones stay as aliases that the kernel
 * answers with a 301, so links never break. An unsafe set is ignored as a whole and the defaults stay.
 */
final class RoutePatterns
{
    /** Route => [required variables, optional variables]. */
    public const ROUTES = [
        CoreRoutes::THREAD_SHOW => [['thread'], ['slug']],
        CoreRoutes::FORUM_SHOW => [['forum'], []],
        CoreRoutes::TAG_SHOW => [['tag'], []],
        CoreRoutes::GALLERY_ALBUM => [['album'], []],
        CoreRoutes::GALLERY_ITEM => [['item'], []],
    ];

    private const DEFAULT_WORDS = [
        CoreRoutes::THREAD_SHOW => 'threads',
        CoreRoutes::FORUM_SHOW => 'forums',
        CoreRoutes::TAG_SHOW => 'tags',
        CoreRoutes::GALLERY_ALBUM => 'gallery',
        CoreRoutes::GALLERY_ITEM => 'gallery',
    ];

    private const MAX_ALIASES = 8;

    /**
     * @param list<RouteDefinition> $definitions
     * @param array<string, array{path: string, aliases: list<string>}> $patterns
     *
     * @return array{definitions: list<RouteDefinition>, dropped: array<string, list<string>>}
     */
    public static function apply(array $definitions, array $patterns): array
    {
        if ($patterns === []) {
            return ['definitions' => $definitions, 'dropped' => []];
        }

        try {
            return self::build($definitions, $patterns);
        } catch (InvalidArgumentException) {
            return ['definitions' => $definitions, 'dropped' => []];
        }
    }

    /**
     * The same as apply() but reports why a set is refused (for the extension's settings page).
     *
     * @param list<RouteDefinition> $definitions
     * @param array<string, array{path: string, aliases: list<string>}> $patterns
     *
     * @return array{definitions: list<RouteDefinition>, dropped: array<string, list<string>>}
     *
     * @throws InvalidArgumentException `route_pattern_<reason>:<route>`.
     */
    public static function build(array $definitions, array $patterns): array
    {
        $byName = [];
        foreach ($definitions as $definition) {
            $byName[$definition->name] = $definition;
        }

        $used = [];
        foreach ($definitions as $definition) {
            if (!isset(self::ROUTES[$definition->name]) && $definition->name !== CoreRoutes::THREAD_PAGE) {
                $used[self::firstSegment($definition->path)] = true;
            }
        }

        $paths = [];
        $words = [];
        foreach ($patterns as $route => $pattern) {
            if (!isset(self::ROUTES[$route], $byName[$route])) {
                throw new InvalidArgumentException('route_pattern_route:' . $route);
            }
            self::check($route, $pattern['path'], $used);
            $word = self::firstSegment($pattern['path']);
            if (isset($words[$word]) && $word !== self::DEFAULT_WORDS[$route]) {
                throw new InvalidArgumentException('route_pattern_word:' . $route);
            }
            $words[$word] = true;
            if (count($pattern['aliases']) > self::MAX_ALIASES) {
                throw new InvalidArgumentException('route_pattern_aliases:' . $route);
            }
            foreach ($pattern['aliases'] as $alias) {
                self::check($route, $alias, $used, true);
            }
            $paths[$route] = $pattern;
        }

        $result = [];
        $aliases = [];
        $dropped = [];
        foreach ($definitions as $definition) {
            $route = $definition->name === CoreRoutes::THREAD_PAGE ? CoreRoutes::THREAD_SHOW : $definition->name;
            if (!isset($paths[$route])) {
                $result[] = $definition;
                continue;
            }

            $suffix = $definition->name === CoreRoutes::THREAD_PAGE ? '/page-{page}' : '';
            $new = $paths[$route]['path'] . $suffix;
            $result[] = self::copy($definition, $definition->name, $new);
            if (!str_contains($new, '{slug}') && isset($definition->requirements['slug'])) {
                $dropped[$definition->name] = ['slug'];
            }

            $old = array_values(array_unique(array_diff(
                [...array_map(static fn(string $path): string => $path . $suffix, $paths[$route]['aliases']),
                    $definition->path],
                [$new],
            )));
            foreach ($old as $index => $path) {
                $name = $definition->name . '.alias' . ($index + 1);
                $aliases[] = self::copy($definition, $name, $path, $definition->name);
            }
        }

        // Aliases after every real route, so the new address always wins a tie.
        return ['definitions' => [...$result, ...$aliases], 'dropped' => $dropped];
    }

    /**
     * Problems a compiled set would have: an address of a moved page that another route answers, or a page of
     * another route that a moved page now answers. Used before saving, not on every request.
     *
     * @param list<RouteDefinition> $definitions The result of build().
     * @param array<string, array<string, string>> $samples Route => sample variables.
     *
     * @return list<string> Route names that conflict.
     */
    public static function conflicts(array $definitions, array $samples): array
    {
        $router = new Router(CompiledRoutes::compile($definitions));
        $conflicts = [];
        foreach ($definitions as $definition) {
            $variables = self::variables($definition->path);
            $isMoved = isset($samples[$definition->name]) || $definition->name === CoreRoutes::THREAD_PAGE;
            if ($definition->aliasOf !== null || ($variables !== [] && !$isMoved)) {
                continue;
            }
            $values = $samples[$definition->name] ?? ($definition->name === CoreRoutes::THREAD_PAGE
                ? ($samples[CoreRoutes::THREAD_SHOW] ?? []) + ['page' => '2'] : []);
            try {
                $path = $router->generate($definition->name, array_intersect_key($values, array_flip($variables)));
                $request = Request::create($path, $definition->methods[0]);
                $matched = $router->match($request)['_route'] ?? null;
            } catch (ExceptionInterface | InvalidArgumentException) {
                $matched = null;
            }
            if ($matched !== $definition->name) {
                $conflicts[] = $definition->name;
            }
        }

        return $conflicts;
    }

    /** @param array<string, true> $used */
    private static function check(string $route, string $path, array $used, bool $alias = false): void
    {
        [$required, $optional] = self::ROUTES[$route];
        $variables = self::variables($path);
        $word = self::firstSegment($path);
        $rest = (string) preg_replace('/\{[a-z][a-z0-9_]*\}/', '', $path);
        if (
            strlen($path) > 120 || preg_match('~^/[a-z0-9][a-z0-9-]{0,39}(?:/[a-z0-9{}_-]+)*$~D', $path) !== 1
            || str_contains($rest, '{') || str_contains($rest, '}') || str_contains($path, '}{')
        ) {
            throw new InvalidArgumentException('route_pattern_syntax:' . $route);
        }
        // A route may keep its own default word (e.g. /threads/...); any other word of a core route is taken.
        if (!$alias && isset($used[$word]) && $word !== self::DEFAULT_WORDS[$route]) {
            throw new InvalidArgumentException('route_pattern_word:' . $route);
        }
        sort($variables);
        $known = [...$required, ...$optional];
        if (
            array_diff($required, $variables) !== [] || array_diff($variables, $known) !== []
            || count($variables) !== count(array_unique($variables))
        ) {
            throw new InvalidArgumentException('route_pattern_variables:' . $route);
        }
    }

    private static function copy(
        RouteDefinition $definition,
        string $name,
        string $path,
        ?string $aliasOf = null,
    ): RouteDefinition {
        $variables = self::variables($path);

        return new RouteDefinition(
            $name,
            $path,
            $aliasOf === null ? $definition->methods : ['GET'],
            array_intersect_key($definition->requirements, array_flip($variables)),
            $definition->requiresHttps,
            $aliasOf,
        );
    }

    /** @return list<string> */
    private static function variables(string $path): array
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $path, $matches);

        return $matches[1];
    }

    private static function firstSegment(string $path): string
    {
        return explode('/', ltrim($path, '/'), 2)[0];
    }
}
