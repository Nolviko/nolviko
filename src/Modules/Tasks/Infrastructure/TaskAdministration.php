<?php

declare(strict_types=1);

namespace Nolviko\Modules\Tasks\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use InvalidArgumentException;

/** Bounded redacted task diagnostics and owner recovery. Payloads and lease tokens never reach the UI. */
final class TaskAdministration
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function page(string $status, int $before): array
    {
        if (!in_array($status, ['', 'pending', 'running', 'failed', 'completed'], true) || $before < 0) {
            throw new InvalidArgumentException('tasks_filter_invalid');
        }

        $where = $status === '' ? '' : ' AND status = ?';
        $parameters = [$before, $before, ...($status === '' ? [] : [$status])];

        return $this->db->fetchAllAssociative(
            'SELECT id, event_type, status, attempts, available_at, created_at, '
            . 'last_error FROM outbox_events WHERE (? = 0 OR id < ?)' . $where . ' ORDER BY id DESC LIMIT 51',
            $parameters,
        );
    }

    public function summary(): array
    {
        $now = $this->now();
        $interval = $this->db->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? "INTERVAL '5 minutes'" : 'INTERVAL 5 MINUTE';
        $conditions = ['failed' => "status = 'failed'",
            'overdue' => "status = 'pending' AND available_at < " . $now . ' - ' . $interval,
            'expired' => "status = 'running' AND lease_expires_at <= " . $now];
        $result = ['capped' => false];

        foreach ($conditions as $name => $where) {
            $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM (SELECT id FROM outbox_events WHERE '
                . $where . ' LIMIT 1001) bounded_summary');
            $result[$name] = min(1000, $count);
            $result['capped'] = $result['capped'] || $count > 1000;
        }

        return $result;
    }

    public function retry(int $id): bool
    {
        return $this->db->executeStatement("UPDATE outbox_events SET status = 'pending', attempts = 0, "
            . 'owner_token = NULL, lease_expires_at = NULL, available_at = ' . $this->now() . ', '
            . 'last_error = NULL, revision = revision + 1 WHERE id = ? AND status = ?', [$id, 'failed']) === 1;
    }
    private function now(): string
    {
        return $this->db->getDatabasePlatform() instanceof PostgreSQLPlatform
            ? "(clock_timestamp() AT TIME ZONE 'UTC')" : 'CURRENT_TIMESTAMP(6)';
    }
}
