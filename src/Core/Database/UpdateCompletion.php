<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Updates\FileSwitchPlan;
use Nolviko\Core\Updates\RecoveryJobContext;
use Nolviko\Core\Updates\UpdateRun;
use Nolviko\Core\Updates\UpdateSmokeCheck;
use Nolviko\Core\Updates\UpdateStage;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/** Commit the monotonic installed identity before opening an explicitly job-owned maintenance lock. */
final readonly class UpdateCompletion
{
    public function __construct(
        private Connection $connection,
        private UpdateJournal $journal,
        private UpdateSmokeCheck $smoke,
        private string $site,
    ) {
    }

    public function finish(
        RecoveryJobContext $context,
        FileSwitchPlan $plan,
        UpdateRun $run,
        #[SensitiveParameter] string $token,
    ): UpdateRun {
        if (
            $this->connection->isTransactionActive() || $run->stage !== UpdateStage::SmokeChecked
            || $run->manifestHash !== $plan->manifestHash
        ) {
            throw new RuntimeException('update_completion_refused');
        }

        $this->smoke->run($plan);
        $this->connection->beginTransaction();

        try {
            $current = $this->connection->fetchAssociative(
                'SELECT release_sequence, manifest_hash, job_id FROM installed_release WHERE id = 1 FOR UPDATE',
            );

            if (($current === false ? 0 : (int) $current['release_sequence']) !== $context->data['floor']) {
                throw new RuntimeException('update_installed_sequence_changed');
            }

            $values = [
                'release_sequence' => $context->manifest->sequence,
                'release_id' => $context->manifest->releaseId,
                'manifest_hash' => $plan->manifestHash,
                'job_id' => $run->id,
            ];

            if (is_string($context->data['release_version'] ?? null)) {
                $values['release_version'] = (new \Nolviko\Core\Updates\ReleaseVersion(
                    $context->data['release_version'],
                ))->value;
            }

            if ($current === false) {
                $this->connection->insert('installed_release', ['id' => 1] + $values);
            } else {
                $this->connection->update('installed_release', $values, ['id' => 1]);
            }

            $finished = $this->journal->advance(
                $run->id,
                $token,
                $run->revision,
                UpdateStage::Completed,
                $this->now(),
            );
            $this->connection->commit();
        } catch (Throwable $failure) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $failure;
        }

        $this->open($context, $plan, $finished);

        return $finished;
    }

    public function open(RecoveryJobContext $context, FileSwitchPlan $plan, UpdateRun $run): void
    {
        $installed = $this->connection->fetchAssociative(
            'SELECT release_sequence, manifest_hash, job_id FROM installed_release WHERE id = 1',
        );

        if (
            $run->stage !== UpdateStage::Completed || $installed === false
            || $installed['job_id'] !== $run->id || $installed['manifest_hash'] !== $plan->manifestHash
            || (int) $installed['release_sequence'] !== $context->manifest->sequence
        ) {
            throw new RuntimeException('update_completion_identity_mismatch');
        }

        $lock = $this->site . '/config/maintenance.lock';

        if (!file_exists($lock) && !is_link($lock)) {
            return;
        }

        $this->smoke->integrity($plan);

        // Plain/manual or another job's maintenance lock must be removed by its owner, never guessed here.
        if (
            is_link($lock) || !is_file($lock) || filesize($lock) > 128
            || file_get_contents($lock) !== 'nolviko-update:' . $run->id . "\n" || !unlink($lock)
        ) {
            throw new RuntimeException('update_maintenance_owner_action_required');
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            (string) $this->connection->fetchOne('SELECT UTC_TIMESTAMP(6)'),
            new \DateTimeZone('UTC')
        );
    }
}
