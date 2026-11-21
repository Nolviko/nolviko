<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Closure;
use Doctrine\DBAL\Connection;

/**
 * The permission-rules version (VersionCounter::PERMISSION_RULES) read once per request and shared by every cache
 * that keys on it: forum visibility, group rules and the forum tree (2026-11-21 benchmark, optimisation round 2).
 */
final class PermissionRulesVersion
{
    private ?int $current = null;

    /** @param Closure(): Connection $connection Opened only when a cache needs the version. */
    public function __construct(private readonly Closure $connection)
    {
    }

    public function current(): int
    {
        return $this->current ??= (new VersionCounter(($this->connection)()))->current(
            VersionCounter::PERMISSION_RULES,
        );
    }
}
