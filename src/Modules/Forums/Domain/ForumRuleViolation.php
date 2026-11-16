<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Domain;

use DomainException;

/**
 * A forum change the rules forbid; the message is a diagnostic ID: `forum_not_found`, `forum_parent_not_found`,
 * `forum_move_into_descendant`, `forum_too_deep`, `forum_slug_invalid`, `forum_slug_taken`, `forum_has_children`,
 * `forum_has_threads`.
 */
final class ForumRuleViolation extends DomainException
{
    public function __construct(public readonly string $diagnostic)
    {
        parent::__construct($diagnostic);
    }
}
