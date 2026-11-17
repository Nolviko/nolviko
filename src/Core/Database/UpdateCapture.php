<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Core\Updates\BackupVerification;
use Nolviko\Core\Updates\RecoveryJobContext;
use Nolviko\Core\Updates\ReleaseCompatibility;
use Nolviko\Core\Updates\SiteAccessLock;
use Nolviko\Core\Updates\UpdatePreflightFailed;
use Nolviko\Core\Updates\UpdateRun;
use Nolviko\Core\Updates\UpdateStage;
use RuntimeException;

/** Drain governed requests/tasks before the coherent snapshot; preserve an owned maintenance latch on every failure. */
final readonly class UpdateCapture
{
    public function __construct(
        private Connection $db,
        private UpdateJournal $journal,
        private string $site,
        private string $storage,
    ) {
    }

    public function run(RecoveryJobContext $context, UpdateRun $run, string $token): UpdateRun
    {
        if (!in_array($run->stage, [UpdateStage::Staged, UpdateStage::BackedUp], true)) {
            throw new RuntimeException('update_capture_stage_invalid');
        }
        $path = $this->site . '/config/maintenance.lock';
        $marker = 'nolviko-update:' . $run->id . "\n";
        if (!file_exists($path) && !is_link($path)) {
            $stream = @fopen($path, 'xb');
            if ($stream === false) {
                throw new RuntimeException('update_maintenance_unavailable');
            }
            try {
                if (!chmod($path, 0600) || fwrite($stream, $marker) !== strlen($marker) || !fflush($stream)) {
                    throw new RuntimeException('update_maintenance_unavailable');
                }
            } finally {
                fclose($stream);
            }
        }
        if (is_link($path) || !is_file($path) || filesize($path) > 128 || file_get_contents($path) !== $marker) {
            throw new RuntimeException('update_maintenance_owner_action_required');
        }
        $lease = (new SiteAccessLock($this->storage))->acquire(true);
        if ($lease === false) {
            throw new RuntimeException('update_requests_draining');
        }
        try {
            $compatibility = ReleaseCompatibility::fromArchive($context->data['archive'], $context->manifest);
            $issues = (new UpdatePreflight(
                $this->db,
                $this->site,
                $this->storage,
                SqlDialect::driver($this->db),
            ))->issues($compatibility, $context->manifest, true, true);
            if ($issues !== []) {
                throw new UpdatePreflightFailed($issues);
            }
            $backups = new BackupChunkStore($context->data['backup_root'], $this->site . '/public');
            if ($run->stage === UpdateStage::Staged) {
                $paths = array_keys($context->data['installed']);
                foreach (
                    ['config/local.php', 'config/install.lock', 'config/update-trust.json',
                    'config/import-sources.json'] as $private
                ) {
                    if (is_file($this->site . '/' . $private)) {
                        $paths[] = $private;
                    }
                }
                $run = (new UpdateBackup(DatabaseBackups::for($this->db), $this->journal))->export(
                    $run->id,
                    $token,
                    $run->revision,
                    $backups,
                    $this->site,
                    array_values(array_unique($paths)),
                );
            }
            $handle = $this->journal->backup($run->id) ?? throw new RuntimeException('update_backup_missing');
            BackupVerification::verify($backups, $handle, $context->data['installed']);
            return $this->journal->advance(
                $run->id,
                $token,
                $run->revision,
                UpdateStage::Maintenance,
                new DateTimeImmutable('now', new DateTimeZone('UTC'))
            );
        } finally {
            SiteAccessLock::release($lease);
        }
    }
}
