<?php

declare(strict_types=1);

namespace Nolviko\Core\Events;

/** Private worker value, never a response or a log context. Token/revision identify exactly one attempt. */
final readonly class OutboxLease
{
    public function __construct(
        public int $id,
        public string $type,
        public int $aggregateId,
        public string $payload,
        public string $token,
        public int $revision,
        public int $attempt,
    ) {
    }
}
