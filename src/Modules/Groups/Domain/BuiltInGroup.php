<?php

declare(strict_types=1);

namespace Nolviko\Modules\Groups\Domain;

/**
 * The groups every forum has. IDs and slugs are stable (other code, extensions and imports may rely on them),
 * the migration creates them, and they cannot be deleted. Custom groups get IDs from FIRST_CUSTOM_ID up.
 *
 * Guests stands for visitors who are not logged in and never has members; every account belongs to
 * Registered (the registration step adds it).
 */
enum BuiltInGroup: int
{
    case Guests = 1;
    case Registered = 2;
    case Moderators = 3;
    case Administrators = 4;

    public const FIRST_CUSTOM_ID = 100;

    public function slug(): string
    {
        return strtolower($this->name);
    }

    public static function isBuiltIn(int $id): bool
    {
        return self::tryFrom($id) !== null;
    }
}
