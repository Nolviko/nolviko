<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\Migration\ExpandOnlyPolicy;
use Nolviko\Core\Database\Migration\MigrationRegistry;
use Nolviko\Core\Database\Migration\MigrationRunner;
use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Core\Updates\BackupVerification;
use Nolviko\Core\Updates\FileSwitchPlan;
use Nolviko\Core\Updates\UpdateRun;
use Nolviko\Core\Updates\UpdateSmokeCheck;
use Nolviko\Core\Updates\UpdateStage;
use RuntimeException;
use SensitiveParameter;

/**
 * Bounded post-switch coordination. Registry/probes must describe the selected new release, not the old controller.
 */
final class UpdateFinalization
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UpdateJournal $journal,
        private readonly MigrationRegistry $registry,
        private readonly string $backend,
        private readonly BackupChunkStore $backups,
        private readonly UpdateSmokeCheck $smoke,
    ) {
    }

    public function migrate(
        FileSwitchPlan $plan,
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
    ): UpdateRun {
        $guard = $this->guard($plan, $id, $token, $revision, UpdateStage::FilesSwitched);
        $guard();

        if ($this->connection->isTransactionActive()) {
            throw new RuntimeException('update_migration_transaction_active');
        }

        $handle = $this->journal->backup($id) ?? throw new RuntimeException('update_switch_backup_missing');
        $required = [];

        foreach ($plan->operations as $operation) {
            if ($operation['old'] !== null) {
                $required[$operation['path']] = $operation['old'];
            }
        }

        BackupVerification::verify($this->backups, $handle, $required);
        $this->smoke->integrity($plan);
        $runner = new MigrationRunner($this->connection, $this->registry, $this->backend, guard: $guard);

        foreach ($runner->pending() as $migrationId) {
            $migration = $this->registry->find($migrationId)
                ?? throw new RuntimeException('update_migration_definition_missing');
            ExpandOnlyPolicy::assertAllowed($migration, $this->backend);
        }

        $run = $this->journal->find($id) ?? throw new RuntimeException('update_not_found');
        $report = $runner->run(1, false);
        $guard();

        return $this->journal->recordFinalization(
            $id,
            $token,
            $revision,
            $plan->manifestHash,
            UpdateStage::FilesSwitched,
            $report->complete ? UpdateStage::MigrationsApplied : UpdateStage::FilesSwitched,
            $run->cursor + $report->steps,
        );
    }

    public function smoke(
        FileSwitchPlan $plan,
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
    ): UpdateRun {
        $guard = $this->guard($plan, $id, $token, $revision, UpdateStage::MigrationsApplied);
        $guard();
        $runner = new MigrationRunner($this->connection, $this->registry, $this->backend);

        if ($runner->pending() !== []) {
            throw new RuntimeException('update_smoke_migrations_pending');
        }

        $this->smoke->run($plan);
        $guard();

        return $this->journal->recordFinalization(
            $id,
            $token,
            $revision,
            $plan->manifestHash,
            UpdateStage::MigrationsApplied,
            UpdateStage::SmokeChecked,
            0,
        );
    }

    private function guard(
        FileSwitchPlan $plan,
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        UpdateStage $stage,
    ): Closure {
        return function () use ($plan, $id, $token, $revision, $stage): void {
            $this->journal->assertFinalizationLease($id, $token, $revision, $plan->manifestHash, $stage);
        };
    }
}
