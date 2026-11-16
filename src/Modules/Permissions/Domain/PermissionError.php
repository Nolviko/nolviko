<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

use DomainException;

/**
 * A catalog or value problem; the message is a diagnostic ID: `permission_key_invalid:<key>`,
 * `permission_key_duplicate:<key>`, `permission_definition_invalid:<key>`, `permission_unknown:<key>` or
 * `permission_value_invalid:<key>`.
 */
final class PermissionError extends DomainException
{
    public function __construct(public readonly string $diagnostic)
    {
        parent::__construct($diagnostic);
    }
}
