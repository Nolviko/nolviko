<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owner-controlled maintenance latch in private config, checked before routing, session or database access.
 * Never expires: only explicit owner recovery removes it. Marker contents are never read or executed.
 */
final class MaintenanceGuard implements RouteGuard
{
    public const FILE = 'maintenance.lock';

    public function __construct(
        private readonly string $configDirectory,
        private readonly ErrorResponder $errors,
    ) {
    }

    public function guard(array $parameters, Request $request): ?Response
    {
        $path = $this->configDirectory . '/' . self::FILE;
        clearstatcache(true, $path);

        if (!file_exists($path) && !is_link($path)) {
            return null;
        }

        return $this->errors->maintenance();
    }
}
