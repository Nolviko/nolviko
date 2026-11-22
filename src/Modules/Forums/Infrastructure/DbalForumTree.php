<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\Cache;
use Nolviko\Modules\Permissions\Domain\ArrayForumTree;
use Nolviko\Modules\Permissions\Domain\ForumLocks;
use Nolviko\Modules\Permissions\Domain\ProtectedForumTree;
use Throwable;

/**
 * The stored forum tree as the permission module's ForumTree: one query on first use per request (ADR-027),
 * then ArrayForumTree in memory. It also lists the password-protected forums (ADR-041) with a fingerprint of
 * each password hash; setting or removing a password bumps the rules version, which is part of the cache key.
 */
final class DbalForumTree implements ProtectedForumTree
{
    public const CACHE_SECONDS = 300;

    private ?ArrayForumTree $tree = null;

    /** @var array<int, string> */
    private array $protected = [];

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

    public function protectedForums(): array
    {
        $this->tree();

        return $this->protected;
    }

    private function tree(): ArrayForumTree
    {
        if ($this->tree === null) {
            $key = $this->cache !== null && $this->version !== null ? 'tree2.' . ($this->version)() : null;
            $stored = $key === null ? null : $this->cache?->get($key);

            if (!is_array($stored) || !is_array($stored['parents'] ?? null) || !is_array($stored['locked'] ?? null)) {
                $stored = ['parents' => [], 'locked' => []];
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT id, parent_id FROM forums ORDER BY sort_order, id',
                );

                foreach ($rows as $row) {
                    $stored['parents'][(int) $row['id']] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
                }

                $stored['locked'] = $this->passwords();

                if ($key !== null) {
                    $this->cache?->set($key, $stored, self::CACHE_SECONDS);
                }
            }

            $this->protected = $stored['locked'];
            $this->tree = new ArrayForumTree($stored['parents']);
        }

        return $this->tree;
    }

    /** @return array<int, string> Before migration 0075 there is no password column: nothing is protected. */
    private function passwords(): array
    {
        if ($this->connection->isTransactionActive()) {
            $exists = $this->connection->fetchOne(
                "SELECT 1 FROM migration_history WHERE id = ? AND status = 'applied'",
                [ForumExtrasColumns::ID],
            );

            if ($exists === false) {
                return [];
            }
        }

        try {
            $rows = $this->connection->fetchAllKeyValue(
                "SELECT id, password_hash FROM forums WHERE password_hash IS NOT NULL AND password_hash <> ''",
            );
        } catch (Throwable $failure) {
            // Only a missing column (an update still running) means "no passwords"; any other failure must not
            // open protected forums.
            $applied = $this->connection->fetchOne(
                "SELECT 1 FROM migration_history WHERE id = ? AND status = 'applied'",
                [ForumExtrasColumns::ID],
            );

            if ($applied !== false) {
                throw $failure;
            }

            return [];
        }

        $locked = [];

        foreach ($rows as $id => $hash) {
            $locked[(int) $id] = ForumLocks::fingerprint((string) $hash);
        }

        return $locked;
    }
}
