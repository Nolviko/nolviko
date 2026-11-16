<?php

declare(strict_types=1);

namespace Nolviko\Core\Events;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\OutboxQueue;
use RuntimeException;
use Throwable;

/** Small cooperative batches. Database handlers and acknowledgement commit together; unknown types stay queued. */
final class CooperativeTaskRunner
{
    /** @param array<string, Closure(OutboxLease, Closure(): void): bool> $handlers Server-owned implementations. */
    public function __construct(
        private readonly Connection $connection,
        private readonly OutboxQueue $queue,
        private readonly array $handlers,
        private readonly Closure $allowed,
    ) {
    }

    /** @return array{completed: int, progress: int, failed: int} */
    public function run(int $limit = 3, int $milliseconds = 2000): array
    {
        if (
            $limit < 1 || $limit > 10 || $milliseconds < 10 || $milliseconds > 5000
            || $this->connection->isTransactionActive()
        ) {
            throw new RuntimeException('outbox_runner_budget_invalid');
        }

        $deadline = hrtime(true) + $milliseconds * 1000000;
        $result = ['completed' => 0, 'progress' => 0, 'failed' => 0];

        for ($step = 0; $step < $limit && hrtime(true) < $deadline; ++$step) {
            if (($this->allowed)() !== true) {
                break;
            }

            $lease = $this->queue->claim(array_keys($this->handlers));

            if ($lease === null) {
                break;
            }

            $guard = function () use ($lease, $deadline): void {
                if (hrtime(true) >= $deadline || ($this->allowed)() !== true) {
                    throw new RuntimeException('outbox_budget_or_maintenance');
                }

                $this->queue->guard($lease);
            };

            try {
                $finished = $this->connection->transactional(function () use ($lease, $guard): bool {
                    $guard();
                    $complete = ($this->handlers[$lease->type])($lease, $guard);
                    $guard();

                    if ($complete === false) {
                        $this->queue->defer($lease);
                    } else {
                        $this->queue->complete($lease);
                    }

                    return $complete !== false;
                });
                ++$result[$finished ? 'completed' : 'progress'];
            } catch (Throwable) {
                $this->queue->retry($lease);
                ++$result['failed'];
            }
        }

        return $result;
    }
}
