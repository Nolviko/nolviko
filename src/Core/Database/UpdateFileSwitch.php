<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Core\Updates\BackupVerification;
use Nolviko\Core\Updates\FileSwitchPlan;
use Nolviko\Core\Updates\ResumableFileSwap;
use Nolviko\Core\Updates\UpdateRun;
use RuntimeException;
use SensitiveParameter;

/**
 * The frozen control runtime owns this coordinator; never bootstrap a resume through partially replaced vendor/code.
 */
final class UpdateFileSwitch
{
    public function __construct(
        private readonly UpdateJournal $journal,
        private readonly BackupChunkStore $backups,
        private readonly ResumableFileSwap $files,
    ) {
    }

    public function step(
        FileSwitchPlan $plan,
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        int $budget = ResumableFileSwap::MAX_STEP_ENTRIES,
    ): UpdateRun {
        $guard = function () use ($id, $token, $revision, $plan): void {
            $this->journal->assertFileSwitchLease($id, $token, $revision, $plan->manifestHash);
        };
        $guard();
        $handle = $this->journal->backup($id) ?? throw new RuntimeException('update_switch_backup_missing');
        $required = [];

        foreach ($plan->operations as $operation) {
            if ($operation['old'] !== null) {
                $required[$operation['path']] = $operation['old'];
            }
        }

        BackupVerification::verify($this->backups, $handle, $required);
        $progress = $this->files->step($plan, $id, $guard, $budget);

        return $this->journal->recordFileSwitch(
            $id,
            $token,
            $revision,
            $plan->manifestHash,
            $progress->nextIndex,
            $progress->complete,
        );
    }
}
