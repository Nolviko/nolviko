<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Native content index. Building on an existing large database requires a planned maintenance window. */
final class SearchIndex implements Migration
{
    public function id(): string
    {
        return '0033_search_index';
    }

    public function phase(): MigrationPhase
    {
        return MigrationPhase::Expand;
    }

    public function statements(string $backend): array
    {
        if ($backend === 'postgresql') {
            return SearchDialect::indexStatements($backend);
        }

        if ($backend !== 'mysql') {
            throw new MigrationException('migration_backend_unsupported:' . $backend);
        }

        return ['CREATE FULLTEXT INDEX ft_posts_content ON posts (content)'];
    }
}
