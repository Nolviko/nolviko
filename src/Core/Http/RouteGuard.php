<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Runs after a route matched and before its handler is built; may answer the request instead.
 */
interface RouteGuard
{
    /**
     * @param array<string, mixed> $parameters Route attributes from the matcher, including `_route`.
     *
     * @return Response|null A response that replaces the handler's, or null to continue.
     *
     * @throws ResourceNotFoundException To answer 404, as if the route did not exist.
     */
    public function guard(array $parameters, Request $request): ?Response;
}
