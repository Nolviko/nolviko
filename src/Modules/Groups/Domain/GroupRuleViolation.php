<?php

declare(strict_types=1);

namespace Nolviko\Modules\Groups\Domain;

use DomainException;

/**
 * A group change that the rules forbid; the message is a diagnostic ID:
 * `group_builtin_protected`, `group_slug_invalid`, `group_slug_taken`, `group_not_found`,
 * `group_guests_no_members`, `group_primary_required`, `group_not_member`.
 */
final class GroupRuleViolation extends DomainException
{
    public function __construct(public readonly string $diagnostic)
    {
        parent::__construct($diagnostic);
    }
}
