<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Core\Extensions\ExtensionFailure;
use Nolviko\Core\Extensions\ExtensionRegistry;

/** Subscriber effects and receipts commit with the core outbox acknowledgement; disabled subscribers wait. */
final readonly class ExtensionEvents
{
    public function __construct(
        private Connection $connection,
        private ExtensionRegistry $registry,
    ) {
    }

    /** @return array<string, Closure(OutboxLease, Closure): bool> */
    public function handlers(): array
    {
        $handlers = [];
        foreach ($this->registry->subscribers as $event => $subscribers) {
            $handlers[$event] = function (OutboxLease $lease, Closure $guard) use ($subscribers): bool {
                if (!$this->connection->isTransactionActive()) {
                    throw new ExtensionFailure('extension_event_requires_transaction');
                }

                foreach ($subscribers as $owner => $handler) {
                    $guard();
                    $done = $this->connection->fetchOne(
                        'SELECT event_id FROM extension_event_receipts WHERE extension_id = ? AND event_id = ?',
                        [$owner, $lease->id],
                    );
                    if ($done !== false) {
                        continue;
                    }

                    $payload = json_decode($lease->payload, true, 20, JSON_THROW_ON_ERROR);
                    $handler($payload, $this->connection, $guard);
                    $guard();
                    $this->connection->insert('extension_event_receipts', [
                        'extension_id' => $owner, 'event_id' => $lease->id,
                    ]);
                }

                return true;
            };
        }

        return $handlers;
    }
}
