<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0017: `threads.moved_to_thread_id` (M03.08). A move can leave a redirect stub in the old forum: a thread row
 * without posts that points at the moved thread. Stubs are listed like threads but never counted in forum
 * statistics and take no replies; deleting the target deletes its stubs (CASCADE).
 */
final class ThreadRedirectsTable implements Migration
{
    public const ID = '0017_thread_redirects';

    public function id(): string
    {
        return self::ID;
    }

    public function phase(): MigrationPhase
    {
        return MigrationPhase::Expand;
    }

    public function statements(string $backend): array
    {
        if ($backend !== 'mysql') {
            throw new MigrationException('migration_backend_unsupported:' . $backend);
        }

        return [
            'ALTER TABLE threads ADD COLUMN moved_to_thread_id BIGINT NULL, '
                . 'ADD CONSTRAINT fk_threads_moved_to FOREIGN KEY (moved_to_thread_id) REFERENCES threads (id) '
                . 'ON DELETE CASCADE',
        ];
    }
}
