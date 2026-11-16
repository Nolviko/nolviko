<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Closure;
use Nolviko\Core\Updates\BackupChunkStore;

/**
 * A bounded database snapshot for updates (MySqlBackup for the MySQL family, PostgresBackup for PostgreSQL,
 * 2026-11-14). No shell, automatic restore, public endpoint or cross-request snapshot.
 */
interface DatabaseBackup
{
    /**
     * @param Closure(): void $guard Verifies the update lease and quiescence; must not write SQL.
     * @param (Closure(): mixed)|null $captureFiles
     *
     * @return array{id: string, digest: string}
     */
    public function export(BackupChunkStore $store, Closure $guard, ?Closure $captureFiles = null): array;

    /**
     * Restores a trusted local export into an empty, explicitly selected database; never a live-site rollback.
     *
     * @param array{id: string, digest: string} $handle
     */
    public function restore(BackupChunkStore $store, array $handle): void;
}
