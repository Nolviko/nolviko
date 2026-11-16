<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Domain;

/** Frozen read-only source. Rows have a monotonic numeric ID; credentials and raw rows are never view data. */
interface ImportSource
{
    public function identity(): array;

    public function phases(): array;

    public function rows(string $phase, string $after, int $limit): array;

    public function coverage(): array;

    public function asset(array $row): ?ImportAsset;
}
