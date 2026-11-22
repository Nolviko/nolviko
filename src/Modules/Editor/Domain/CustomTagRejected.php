<?php

declare(strict_types=1);

namespace Nolviko\Modules\Editor\Domain;

use InvalidArgumentException;

/** A MyCode definition that failed validation; `diagnostic` is a `diagnostics.*` key suffix (ADR-042). */
final class CustomTagRejected extends InvalidArgumentException
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public readonly string $diagnostic,
        public readonly array $parameters = [],
    ) {
        parent::__construct($diagnostic);
    }
}
