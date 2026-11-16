<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

/**
 * Private extraction progress. A complete stage remains quarantined until separate activation checks pass.
 */
final readonly class ArchiveExtraction
{
    public function __construct(
        public string $directory,
        public int $nextIndex,
        public bool $complete,
    ) {
    }
}
