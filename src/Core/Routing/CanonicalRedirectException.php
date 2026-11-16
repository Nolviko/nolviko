<?php

declare(strict_types=1);

namespace Nolviko\Core\Routing;

use RuntimeException;

/**
 * The request matched only in its canonical form; answered with 301 to that same-origin location.
 */
final class CanonicalRedirectException extends RuntimeException
{
    public function __construct(
        public readonly string $location,
    ) {
        parent::__construct('path_not_canonical');
    }
}
