<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * Why a permission came out as it did, for the admin "explain" view: every rule that was consulted, with its
 * level and source, and the decision.
 */
final class PermissionTrace
{
    /**
     * @param list<array{level: string, forum_id: int|null, source: string, value: string}> $rules Each consulted
     *     rule; `source` is `group:<id>` or `user`.
     */
    public function __construct(
        public readonly string $key,
        public readonly ?int $forumId,
        public readonly PermissionDecision $decision,
        public readonly array $rules,
    ) {
    }
}
