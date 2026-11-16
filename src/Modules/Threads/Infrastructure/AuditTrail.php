<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Events\Outbox;

/**
 * Writes one `audit_logs` row and its outbox event inside the caller's transaction (M03.07, M03.08). The event's
 * dedupe key is the audit ID, so every action is its own event.
 */
final class AuditTrail
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Outbox $outbox,
    ) {
    }

    /**
     * @param string $action Audit action, `<target type>.<verb>` (`thread.move`).
     * @param string $event Outbox event type, past tense (`thread.moved`).
     * @param array<string, int|string|bool|null> $details
     */
    public function record(
        string $action,
        string $event,
        string $targetType,
        int $targetId,
        int $actorId,
        ?string $reason,
        string $at,
        array $details,
    ): int {
        $this->connection->insert('audit_logs', [
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'reason' => $reason,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => $at,
        ]);
        $auditId = (int) $this->connection->lastInsertId();
        $this->outbox->record($event, $targetType, $targetId, [
            $targetType . '_id' => $targetId,
            'actor_id' => $actorId,
            'audit_id' => $auditId,
        ] + $details, $event . ':' . $auditId);

        return $auditId;
    }
}
