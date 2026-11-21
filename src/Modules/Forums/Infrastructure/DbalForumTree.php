<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\Cache;
use Nolviko\Modules\Permissions\Domain\ArrayForumTree;
use Nolviko\Modules\Permissions\Domain\ForumTree;

/**
 * The stored forum tree as the permission module's ForumTree: one query on first use per request (ADR-027),
 * then ArrayForumTree in memory.
 */
final class DbalForumTree implements ForumTree
{
    public const CACHE_SECONDS = 300;

    private ?ArrayForumTree $tree = null;

    /**
     * @param Closure(): int|null $version Current VersionCounter::PERMISSION_RULES (forum moves, creation and
     *     deletion bump it); with $cache the parent map is read from the cache (2026-11-21 benchmark).
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?Cache $cache = null,
        private readonly ?Closure $version = null,
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
            $key = $this->cache !== null && $this->version !== null ? 'tree.' . ($this->version)() : null;
            $parents = $key === null ? null : $this->cache?->get($key);

            if (!is_array($parents)) {
                $parents = [];
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT id, parent_id FROM forums ORDER BY sort_order, id',
                );

                foreach ($rows as $row) {
                    $parents[(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
                }

                if ($key !== null) {
                    $this->cache?->set($key, $parents, self::CACHE_SECONDS);
                }
            }

            $this->tree = new ArrayForumTree($parents);
        }

        return $this->tree;
    }
}
