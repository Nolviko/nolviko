<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

/**
 * A bounded catalog and freshness state; a truncated/empty catalog does not establish version eligibility.
 */
final readonly class ReleaseDiscoveryResult
{
    /**
     * @param list<DiscoveredRelease> $releases
     */
    public function __construct(
        public array $releases,
        public string $status,
        public ?string $diagnostic,
        public int $checkedAt,
        public ?int $lastSuccessAt,
        public int $nextCheckAt,
        public bool $truncated,
    ) {
    }
}
