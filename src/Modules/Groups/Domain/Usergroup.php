<?php

declare(strict_types=1);

namespace Nolviko\Modules\Groups\Domain;

/**
 * A stored user group. `name` is the display name; built-in groups are shown with their translated name
 * (`groups.builtin.<slug>`) instead.
 */
final class Usergroup
{
    public function __construct(
        public readonly int $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly bool $builtIn,
    ) {
    }
}
