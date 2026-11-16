<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use Composer\Semver\Semver;
use Throwable;

/** Uses the approved Composer parser without exposing its types to extension contracts. */
final class ComposerVersionConstraint implements VersionConstraint
{
    public function accepts(string $version, string $constraint): bool
    {
        if (strlen($version) > 128 || strlen($constraint) > 256 || $constraint === '') {
            throw new ExtensionFailure('extension_constraint_invalid');
        }

        try {
            return Semver::satisfies($version, $constraint);
        } catch (Throwable $failure) {
            throw new ExtensionFailure('extension_constraint_invalid', 0, $failure);
        }
    }
}
