<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

/** Application-owned version syntax boundary; invalid input never means compatible. */
interface VersionConstraint
{
    public function accepts(string $version, string $constraint): bool;
}
