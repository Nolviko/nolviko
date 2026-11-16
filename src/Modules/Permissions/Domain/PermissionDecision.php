<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * The outcome of Precedence::decide(): the value and why (the level that decided, for the explanation trace).
 */
final class PermissionDecision
{
    public const NEVER = 'never';

    public const DEFAULT = 'default';

    /**
     * @param string $reason NEVER, DEFAULT or the deciding level: `user_forum`, `user`, `group_forum`, `group`.
     * @param int|null $forumDepth For forum levels: 0 = the forum itself, 1 = its parent, ...
     * @param list<string> $ignored Stored values that no longer fit the definition (ignored, reported).
     */
    public function __construct(
        public readonly bool|int|string $value,
        public readonly string $reason,
        public readonly ?int $forumDepth = null,
        public readonly array $ignored = [],
    ) {
    }

    public function allowed(): bool
    {
        return $this->value === true;
    }
}
