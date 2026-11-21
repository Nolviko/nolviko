<?php

declare(strict_types=1);

namespace Nolviko\Modules\Content\Domain;

use RuntimeException;

/** A refused content add-on change (smiley, icon, MyCode, attachment policy); `diagnostic` names a key. */
final class ContentRejected extends RuntimeException
{
    /** @param array<string, string> $parameters */
    public function __construct(
        public readonly string $diagnostic,
        public readonly array $parameters = [],
    ) {
        parent::__construct($diagnostic);
    }
}
