<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Domain;

/**
 * The value type of a permission (`permissions.value_type`).
 */
enum PermissionType: string
{
    case Bool = 'bool';
    case Int = 'int';
    case Enum = 'enum';
}
