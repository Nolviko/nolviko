<?php

declare(strict_types=1);

namespace Nolviko\Modules\Gallery\Domain;

use RuntimeException;

/** A refused gallery action; the message is a diagnostic key (`diagnostics.<key>`). */
final class GalleryRejected extends RuntimeException
{
    /**
     * @param array<string, int|string> $parameters
     */
    public function __construct(public readonly string $diagnostic, public readonly array $parameters = [])
    {
        parent::__construct($diagnostic);
    }
}
