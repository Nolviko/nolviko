<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0065 (beta.18, roadmap 11): a native full-text index on thread titles for "titles only" search. The threads
 * table is much smaller than posts, so the build is short even on a large board.
 */
final class TitleSearchIndex implements Migration
{
    public const ID = '0065_thread_title_search';

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
        if ($backend === 'postgresql') {
            return ['CREATE INDEX ft_threads_title ON threads USING GIN (' . SearchDialect::POSTGRES_TITLE_VECTOR
                . ')'];
        }

        if ($backend !== 'mysql') {
            throw new MigrationException('migration_backend_unsupported:' . $backend);
        }

        return ['CREATE FULLTEXT INDEX ft_threads_title ON threads (title)'];
    }
}
