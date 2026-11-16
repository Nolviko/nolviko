<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration;

/**
 * What one bounded run did; the installer/updater calls again until `complete`.
 */
final class MigrationReport
{
    /**
     * @param list<string> $applied IDs finished in this run.
     */
    public function __construct(
        public readonly array $applied,
        public readonly int $steps,
        public readonly bool $complete,
    ) {
    }
}
