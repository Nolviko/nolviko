<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Modules\Permissions\Domain\ArrayForumTree;
use Nolviko\Modules\Permissions\Domain\ForumTree;

/**
 * The stored forum tree as the permission module's ForumTree: one query on first use per request (ADR-027),
 * then ArrayForumTree in memory.
 */
final class DbalForumTree implements ForumTree
{
    private ?ArrayForumTree $tree = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function ancestry(int $forumId): array
    {
        return $this->tree()->ancestry($forumId);
    }

    public function forumIds(): array
    {
        return $this->tree()->forumIds();
    }

    private function tree(): ArrayForumTree
    {
        if ($this->tree === null) {
            $parents = [];
            $rows = $this->connection->fetchAllAssociative('SELECT id, parent_id FROM forums ORDER BY sort_order, id');

            foreach ($rows as $row) {
                $parents[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            }

            $this->tree = new ArrayForumTree($parents);
        }

        return $this->tree;
    }
}
