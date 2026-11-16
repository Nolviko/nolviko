<?php

declare(strict_types=1);

namespace Nolviko\Core\Routing;

use RuntimeException;

/**
 * The request path breaks PathPolicy; answered with 400.
 */
final class MalformedPathException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('path_malformed');
    }
}
