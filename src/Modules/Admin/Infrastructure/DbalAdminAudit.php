<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * Audit entries for admin-area actions (M02.15): re-authentication successes and failures, later every admin
 * command. Same `audit_logs` table as moderation (M03.07); the target is the acting user.
 */
final class DbalAdminAudit
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array<string, int|string|bool|null> $details
     */
    public function record(int $actorId, string $action, array $details = []): void
    {
        $this->connection->insert('audit_logs', [
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => 'user',
            'target_id' => $actorId,
            'reason' => null,
            'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ]);
    }
}
