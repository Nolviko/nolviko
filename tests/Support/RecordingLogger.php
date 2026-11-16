<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Captures structured diagnostic records for privacy assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['message' => (string) $message, 'context' => $context];
    }
}
