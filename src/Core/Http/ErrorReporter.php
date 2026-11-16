<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Throwable;

/**
 * Receives the full details of an unexpected failure; the visitor only ever sees the incident ID.
 */
interface ErrorReporter
{
    /**
     * @param array<string, mixed> $context Request facts such as `client_ip`, `method` and `path` (no query).
     */
    public function report(string $incidentId, Throwable $error, array $context = []): void;
}
