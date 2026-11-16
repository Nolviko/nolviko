<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Nolviko\Core\Http\ErrorReporter;
use Throwable;

/**
 * Keeps reported incidents in memory so tests can match the visitor's incident ID to the logged error.
 */
final class RecordingReporter implements ErrorReporter
{
    /** @var array<string, Throwable> Incident ID => error. */
    public array $incidents = [];

    /** @var array<string, array<string, mixed>> Incident ID => request context. */
    public array $contexts = [];

    public function report(string $incidentId, Throwable $error, array $context = []): void
    {
        $this->incidents[$incidentId] = $error;
        $this->contexts[$incidentId] = $context;
    }
}
