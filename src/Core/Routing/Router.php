<?php

declare(strict_types=1);

namespace Nolviko\Core\Routing;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\CompiledUrlGenerator;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\RequestContext;

/**
 * Matches requests against compiled routes and generates URLs from the same tables.
 *
 * Symfony routing types stay behind this class and the Kernel (ADR-005 adapter boundary).
 */
final class Router implements UrlGenerator
{
    private const REDIRECTABLE_METHODS = ['GET', 'HEAD'];

    private readonly CompiledUrlMatcher $matcher;

    private readonly CompiledUrlGenerator $generator;

    /**
     * @param array<string, list<string>> $dropped Parameters a moved route no longer has (RoutePatterns): callers
     *     may still pass them, and they must not turn into a query string.
     */
    public function __construct(
        CompiledRoutes $routes,
        private readonly RequestContext $context = new RequestContext(),
        private readonly array $dropped = [],
    ) {
        $this->matcher = new CompiledUrlMatcher($routes->matcher, $this->context);
        $this->generator = new CompiledUrlGenerator($routes->generator, $this->context);
    }

    /**
     * @return array<string, mixed> Route attributes, including `_route`.
     *
     * @throws MalformedPathException
     * @throws CanonicalRedirectException When only the canonical form of a GET/HEAD path matches.
     * @throws ResourceNotFoundException
     * @throws MethodNotAllowedException
     */
    public function match(Request $request): array
    {
        $this->context->fromRequest($request);
        $path = $request->getPathInfo();
        PathPolicy::assertWellFormed($path);

        try {
            return $this->matcher->match($path);
        } catch (ResourceNotFoundException $notFound) {
            throw $this->canonicalRedirect($request, $path) ?? $notFound;
        }
    }

    public function generate(string $name, array $parameters = []): string
    {
        if (isset($this->dropped[$name])) {
            $parameters = array_diff_key($parameters, array_flip($this->dropped[$name]));
        }

        return $this->generator->generate($name, $parameters);
    }

    private function canonicalRedirect(Request $request, string $path): ?CanonicalRedirectException
    {
        $canonical = PathPolicy::canonical($path);

        if ($canonical === $path || !in_array($request->getMethod(), self::REDIRECTABLE_METHODS, true)) {
            return null;
        }

        try {
            $this->matcher->match($canonical);
        } catch (ResourceNotFoundException | MethodNotAllowedException) {
            return null;
        }

        $query = $request->getQueryString();
        $location = $request->getBaseUrl() . $canonical . ($query === null ? '' : '?' . $query);

        return new CanonicalRedirectException($location);
    }
}
