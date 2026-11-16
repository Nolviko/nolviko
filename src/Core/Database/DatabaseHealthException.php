<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use RuntimeException;

/**
 * The database is unusable for the forum. The message holds diagnostic IDs only, never credentials or the
 * driver's own message (which can quote the user name, host or password).
 */
final class DatabaseHealthException extends RuntimeException
{
    /**
     * @param list<string> $diagnostics
     */
    public function __construct(private readonly array $diagnostics)
    {
        parent::__construct(implode(', ', $diagnostics));
    }

    /**
     * @return list<string>
     */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
