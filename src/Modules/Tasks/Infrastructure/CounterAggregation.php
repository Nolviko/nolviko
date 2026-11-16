<?php

declare(strict_types=1);

namespace Nolviko\Modules\Tasks\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Infrastructure\ScopeSql;

/** Exact synchronous projections stay authoritative; replaying outbox events never increments counters again. */
final class CounterAggregation
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function visible(ForumScope $scope): array
    {
        [$where, $parameters, $types] = ScopeSql::forums($scope, 's.forum_id');
        $row = $this->db->fetchAssociative(
            'SELECT COALESCE(SUM(s.thread_count), 0) AS threads, '
            . 'COALESCE(SUM(s.post_count), 0) AS posts FROM forum_statistics s WHERE ' . $where,
            $parameters,
            $types
        );

        return ['threads' => (int) $row['threads'], 'posts' => (int) $row['posts']];
    }
}
