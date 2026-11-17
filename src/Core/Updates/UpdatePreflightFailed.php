<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use RuntimeException;

/**
 * Every reason an update cannot start (2026-11-17, owner request: name the exact problem so the administrator can
 * act on it). The message stays the first issue for callers that only log it.
 */
final class UpdatePreflightFailed extends RuntimeException
{
    /** @param non-empty-list<string> $issues Issue IDs such as `update_writable_required`. */
    public function __construct(public readonly array $issues)
    {
        parent::__construct($issues[0]);
    }
}
