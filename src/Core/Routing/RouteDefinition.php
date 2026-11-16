<?php

declare(strict_types=1);

namespace Nolviko\Core\Routing;

use InvalidArgumentException;
use Symfony\Component\Routing\Route;

/**
 * One application route declared in PHP. Validated on construction so the URL policy holds for every route.
 *
 * Policy (docs/ARCHITECTURE.md#url-policy): paths are lowercase, have no trailing slash (except `/`), and
 * every `{variable}` has an explicit requirement that accepts no upper-case ASCII letter. GET implies HEAD.
 */
final class RouteDefinition
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/D';

    private const PATH_PATTERN = '~^/(?:[a-z0-9._{}-]+(?:/[a-z0-9._{}-]+)*)?$~D';

    private const VARIABLE_PATTERN = '/\{([a-z][a-z0-9_]*)\}/';

    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** Samples a requirement must reject, so the lowercase canonical form never changes a variable. */
    private const UPPER_CASE_PROBES = ['A', 'Z', 'AA', 'A1', '1A', 'aA', 'Aa'];

    /** Route attribute set on routes that must be served over HTTPS when `http.require_https` is on. */
    public const HTTPS_ATTRIBUTE = '_https';

    /** Route attribute naming the route an alias address redirects to (2026-11-15). */
    public const ALIAS_ATTRIBUTE = '_alias_of';

    /** @var list<string> */
    public readonly array $methods;

    /**
     * @param list<string> $methods
     * @param array<string, string> $requirements Variable => regular expression without delimiters or anchors.
     * @param bool $requiresHttps Login, registration and admin routes (HttpsGuard).
     *
     * @throws InvalidArgumentException When the definition breaks the URL policy.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        array $methods,
        public readonly array $requirements = [],
        public readonly bool $requiresHttps = false,
        public readonly ?string $aliasOf = null,
    ) {
        self::assertName($name);
        self::assertPath($name, $path);
        self::assertRequirements($name, $path, $requirements);
        $this->methods = self::normalizeMethods($name, $methods);
    }

    public function toRoute(): Route
    {
        $defaults = $this->requiresHttps ? [self::HTTPS_ATTRIBUTE => true] : [];
        if ($this->aliasOf !== null) {
            // An older address of another route (2026-11-15): the kernel answers it with a 301 (RoutePatterns).
            $defaults[self::ALIAS_ATTRIBUTE] = $this->aliasOf;
        }

        return new Route($this->path, $defaults, $this->requirements, methods: $this->methods);
    }

    private static function assertName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('route_name_invalid:' . $name);
        }
    }

    private static function assertPath(string $name, string $path): void
    {
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw new InvalidArgumentException('route_path_invalid:' . $name);
        }
    }

    /**
     * @param array<string, string> $requirements
     */
    private static function assertRequirements(string $name, string $path, array $requirements): void
    {
        preg_match_all(self::VARIABLE_PATTERN, $path, $matches);
        $variables = $matches[1];
        sort($variables);
        $required = array_keys($requirements);
        sort($required);

        if ($variables !== $required) {
            throw new InvalidArgumentException('route_requirements_mismatch:' . $name);
        }

        foreach ($requirements as $variable => $pattern) {
            if (!self::rejectsUpperCase($pattern)) {
                throw new InvalidArgumentException('route_requirement_accepts_upper_case:' . $name . '.' . $variable);
            }
        }
    }

    private static function rejectsUpperCase(string $pattern): bool
    {
        foreach (self::UPPER_CASE_PROBES as $probe) {
            $result = @preg_match('{^(?:' . $pattern . ')$}D', $probe);

            if ($result !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $methods
     *
     * @return list<string>
     */
    private static function normalizeMethods(string $name, array $methods): array
    {
        if ($methods === [] || array_diff($methods, self::METHODS) !== []) {
            throw new InvalidArgumentException('route_methods_invalid:' . $name);
        }

        if (in_array('GET', $methods, true)) {
            $methods[] = 'HEAD';
        }

        return array_values(array_unique($methods));
    }
}
