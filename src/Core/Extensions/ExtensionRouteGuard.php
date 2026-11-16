<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use Closure;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Core-owned permission gate for extension routes, independent of the extension handler. */
final readonly class ExtensionRouteGuard implements RouteGuard
{
    public function __construct(
        private ExtensionRegistry $registry,
        private Closure $subjects,
        private ErrorResponder $errors,
    ) {
    }

    public function guard(array $parameters, Request $request): ?Response
    {
        $route = $this->registry->routes[$parameters['_route'] ?? ''] ?? null;
        if ($route === null) {
            return null;
        }

        return ($this->subjects)()->of($request)->allows($route['permission']) ? null : $this->errors->denied();
    }
}
