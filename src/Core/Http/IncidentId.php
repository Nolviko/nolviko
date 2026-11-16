<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

/**
 * Random reference shown to the visitor and written to the log, so support can match the two.
 */
final class IncidentId
{
    public const PATTERN = '/^[0-9a-f]{16}$/D';

    public static function generate(): string
    {
        return bin2hex(random_bytes(8));
    }
}
