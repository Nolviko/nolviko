<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use Closure;
use Nolviko\Core\Http\RouteHandler;

/** Lazy handler factories are composed in the root; unmatched or denied routes never resolve dependencies. */
final readonly class ExtensionHandlers
{
    /** @param array<string, Closure(): RouteHandler> $factories */
    public function __construct(
        private array $factories,
    ) {
    }

    public function find(string $name): ?RouteHandler
    {
        if (!isset($this->factories[$name])) {
            return null;
        }

        $handler = ($this->factories[$name])();
        if (!$handler instanceof RouteHandler) {
            throw new ExtensionFailure('extension_route_handler_invalid');
        }

        return $handler;
    }
}
