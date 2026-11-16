<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

/**
 * Unauthenticated discovery hint, never proof that a release may be installed. Source archive URLs are omitted.
 */
final readonly class DiscoveredRelease
{
    /**
     * @param list<array{id: int, name: string, bytes: int}> $assets Uploaded assets only, not zipball/tarball URLs.
     */
    public function __construct(
        public int $id,
        public string $tag,
        public bool $prerelease,
        public int $publishedAt,
        public array $assets,
    ) {
    }
}
