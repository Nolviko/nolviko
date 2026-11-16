<?php

declare(strict_types=1);

namespace Nolviko\Core\Container;

/**
 * A dependency injected as `Closure(): mixed` and resolved on first call, not when the dependent is built.
 *
 * Still validated for existence at build time; it is not an edge for construction-cycle detection.
 */
final class Lazy
{
    public function __construct(
        public readonly string $id,
    ) {
    }
}
