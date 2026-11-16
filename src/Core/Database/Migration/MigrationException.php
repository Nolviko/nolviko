<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration;

use RuntimeException;
use Throwable;

/**
 * A migration cannot run or failed. The message is a diagnostic ID only; a driver failure is kept as the
 * previous exception for the (redacted) log, never shown to the visitor.
 */
final class MigrationException extends RuntimeException
{
    public function __construct(public readonly string $diagnostic, ?Throwable $previous = null)
    {
        parent::__construct($diagnostic, 0, $previous);
    }
}
