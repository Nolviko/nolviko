<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Nolviko\Core\Updates\UpdateStage;

/** Bounded private history projection; no owner tokens, backup handles or raw diagnostics. */
final class DbalUpdateOverview
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function preparableJob(): ?string
    {
        try {
            $id = $this->connection->fetchOne(
                "SELECT id FROM update_runs WHERE active_slot = 1 AND stage IN "
                    . "('discovered', 'checked', 'downloaded', 'verified', 'staged', 'backed_up') LIMIT 1",
            );

            return is_string($id) ? $id : null;
        } catch (DbalException) {
            return null;
        }
    }

    /** @return list<array<string, string|int>>|null */
    public function read(): ?array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT target, stage, run_condition, checkpoint_cursor, updated_at FROM update_runs '
                    . 'ORDER BY created_at DESC, id DESC LIMIT 20',
            );
            $result = [];

            foreach ($rows as $row) {
                $stage = UpdateStage::tryFrom((string) $row['stage']);
                $state = $stage === UpdateStage::Completed ? 'completed' : (string) $row['run_condition'];
                $states = ['running', 'paused', 'recoverable', 'manual_attention', 'abandoned', 'completed'];
                $result[] = [
                    'target' => (string) $row['target'],
                    'stage' => 'admin.updates.stage_' . ($stage?->value ?? 'unknown'),
                    'state' => 'admin.updates.state_' . (in_array($state, $states, true) ? $state : 'unknown'),
                    'cursor' => max(0, (int) $row['checkpoint_cursor']),
                    'updated' => (string) $row['updated_at'],
                ];
            }

            return $result;
        } catch (DbalException) {
            return null;
        }
    }
}
