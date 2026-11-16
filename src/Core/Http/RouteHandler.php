<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thin HTTP handler for one route. Business rules belong in application services, not here.
 */
interface RouteHandler
{
    /**
     * @param array<string, mixed> $parameters Route attributes from the matcher (e.g. `id`, `_route`).
     */
    public function handle(Request $request, array $parameters): Response;
}
