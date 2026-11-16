<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\Migration\MigrationRegistry;
use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Core\Updates\RecoveryJobContext;
use Nolviko\Core\Updates\ResumableFileSwap;
use Nolviko\Core\Updates\UpdateRun;
use Nolviko\Core\Updates\UpdateSmokeCheck;
use Nolviko\Core\Updates\UpdateStage;
use RuntimeException;
use Throwable;

/** Fixed commands: browser selects a verb; all paths, artifact identity and cursors stay server-owned. */
final readonly class RecoveryDispatcher
{
    public function __construct(
        private Connection $connection,
        private UpdateJournal $journal,
        private string $site,
        private string $recovery,
        private string $backend,
        private bool $targetRuntime,
        private ?UpdateSmokeCheck $smoke,
    ) {
    }

    /** @return array<string, Closure(string, int): array<string, mixed>> */
    public function actions(): array
    {
        $actions = [];

        foreach (['status', 'backup', 'resume', 'migrate', 'smoke', 'finish'] as $action) {
            $actions[$action] = fn(string $job, int $actor): array => $this->execute($job, $action);
        }

        return $actions;
    }

    /** @return array<string, mixed> */
    private function execute(string $job, string $action): array
    {
        $run = $this->journal->find($job) ?? throw new RuntimeException('update_not_found');

        if ($action === 'status') {
            $next = null;

            try {
                $context = RecoveryJobContext::load($this->recovery, $job);

                if (
                    $context->manifest->manifestHash === $run->manifestHash
                    && in_array($run->condition, ['running'], true)
                ) {
                    $next = self::next($run->stage);
                }
            } catch (Throwable) {
                // Missing/unapproved context leaves the admin manual fallback available.
            }

            return self::status($run) + ['next_action' => $next];
        }

        $context = RecoveryJobContext::load($this->recovery, $job);
        $plan = $context->plan();

        if (
            $plan->manifestHash !== $run->manifestHash || $context->manifest->releaseId !== $run->target
            || $action !== self::next($run->stage)
            || ((!in_array($action, ['backup', 'resume'], true)) !== $this->targetRuntime)
        ) {
            throw new RuntimeException('recovery_action_stage_mismatch');
        }

        if ($action === 'finish' && $run->stage === UpdateStage::Completed) {
            $this->completion()->open($context, $plan, $run);

            return self::status($run);
        }

        $floor = $this->connection->createSchemaManager()->tablesExist(['installed_release'])
            ? $this->connection->fetchOne('SELECT release_sequence FROM installed_release WHERE id = 1') : false;

        if (($floor === false ? 0 : (int) $floor) !== $context->data['floor']) {
            throw new RuntimeException('update_installed_sequence_changed');
        }

        $token = $this->journal->claim($job, $this->now());

        try {
            $run = $this->journal->find($job) ?? throw new RuntimeException('update_not_found');
            $backups = new BackupChunkStore($context->data['backup_root'], $this->site . '/public');

            if ($action === 'backup') {
                $run = (new UpdateCapture($this->connection, $this->journal, $this->site, dirname($this->recovery)))
                    ->run($context, $run, $token);
            } elseif ($action === 'resume') {
                $run = (new UpdateFileSwitch(
                    $this->journal,
                    $backups,
                    new ResumableFileSwap($this->site, $context->data['swap_root'])
                ))->step(
                    $plan,
                    $job,
                    $token,
                    $run->revision,
                );
            } elseif ($action === 'finish') {
                $run = $this->completion()->finish($context, $plan, $run, $token);
            } else {
                $finalization = new UpdateFinalization(
                    $this->connection,
                    $this->journal,
                    MigrationRegistry::core(),
                    $this->backend,
                    $backups,
                    $this->smoke ?? throw new RuntimeException('recovery_target_runtime_required'),
                );
                $run = $action === 'migrate'
                    ? $finalization->migrate($plan, $job, $token, $run->revision)
                    : $finalization->smoke($plan, $job, $token, $run->revision);
            }

            return self::status($run);
        } catch (Throwable $failure) {
            if ($action === 'migrate') {
                $latest = $this->journal->find($job);

                if ($latest !== null && $latest->stage === UpdateStage::FilesSwitched) {
                    try {
                        $this->journal->fail(
                            $job,
                            $token,
                            $latest->revision,
                            'manual_attention',
                            'update_migration_reconciliation_required',
                            $this->now()
                        );
                    } catch (Throwable) {
                        // Preserve the original failure; a newer owner is fenced by the journal.
                    }
                }
            }

            throw $failure;
        } finally {
            $latest = $this->journal->find($job);

            if ($latest !== null && $latest->stage !== UpdateStage::Completed) {
                try {
                    $this->journal->release($job, $token, $latest->revision, $this->now());
                } catch (Throwable) {
                    // A failed/stale lease remains fenced; never force-unlock another worker.
                }
            }
        }
    }

    private function completion(): UpdateCompletion
    {
        return new UpdateCompletion(
            $this->connection,
            $this->journal,
            $this->smoke ?? throw new RuntimeException('recovery_target_runtime_required'),
            $this->site
        );
    }

    private static function next(UpdateStage $stage): ?string
    {
        return match ($stage) {
            UpdateStage::Staged, UpdateStage::BackedUp => 'backup',
            UpdateStage::Maintenance => 'resume',
            UpdateStage::FilesSwitched => 'migrate',
            UpdateStage::MigrationsApplied => 'smoke',
            UpdateStage::SmokeChecked, UpdateStage::Completed => 'finish',
            default => null,
        };
    }

    private static function status(UpdateRun $run): array
    {
        return ['stage' => $run->stage->value, 'cursor' => $run->cursor,
            'complete' => $run->stage === UpdateStage::Completed];
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string) $this->connection->fetchOne('SELECT UTC_TIMESTAMP(6)'),
            new DateTimeZone('UTC')
        );
    }
}
