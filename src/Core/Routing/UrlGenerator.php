<?php

declare(strict_types=1);

namespace Nolviko\Core\Routing;

use InvalidArgumentException;

/**
 * Application-owned URL construction by route name; modules and templates never build paths by hand.
 */
interface UrlGenerator
{
    /**
     * Path including the base path of the installation (e.g. `/forum/threads/42`).
     *
     * @param array<string, string|int> $parameters Values for the route variables; extra values become the query.
     *
     * @throws InvalidArgumentException For an unknown route or a value that breaks a requirement.
     */
    public function generate(string $name, array $parameters = []): string;
}
