<?php

declare(strict_types=1);

namespace Nolviko\Core\Logging;

use Nolviko\Core\Http\ErrorReporter;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends unexpected failures to the PSR-3 logger as `unhandled_exception` with the incident ID.
 */
final class LoggerReporter implements ErrorReporter
{
    public const MESSAGE = 'unhandled_exception';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function report(string $incidentId, Throwable $error, array $context = []): void
    {
        $this->logger->error(self::MESSAGE, ['incident' => $incidentId, 'exception' => $error, ...$context]);
    }
}
