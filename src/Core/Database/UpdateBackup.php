<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Core\Updates\FileBackup;
use Nolviko\Core\Updates\UpdateRun;
use RuntimeException;
use SensitiveParameter;

/**
 * Publish a recovery reference only after complete database/files capture and a final fenced journal write.
 */
final class UpdateBackup
{
    public function __construct(private readonly DatabaseBackup $database, private readonly UpdateJournal $journal)
    {
    }

    /**
     * @param list<string> $paths Fixed runtime inventory; caller must stop external filesystem writers.
     */
    public function export(
        string $id,
        #[SensitiveParameter]
        string $token,
        int $revision,
        BackupChunkStore $store,
        string $applicationRoot,
        array $paths,
    ): UpdateRun {
        $guard = function () use ($id, $token, $revision, $applicationRoot): void {
            if (!file_exists($applicationRoot . '/config/maintenance.lock')) {
                throw new RuntimeException('backup_maintenance_required');
            }

            $this->journal->assertBackupLease($id, $token, $revision);
        };
        $handle = $this->database->export(
            $store,
            $guard,
            static fn(): array => FileBackup::export($store, $applicationRoot, $paths, $guard),
        );

        return $this->journal->recordBackup($id, $token, $revision, $handle['id'], $handle['digest']);
    }
}
