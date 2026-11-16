<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Nolviko\Core\Installer\SchemaMigrator;

/**
 * Records migration batches without a database.
 */
final class FakeSchema implements SchemaMigrator
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(
        private readonly int $pending = 1,
    ) {
    }

    public function run(): array
    {
        $this->calls[] = 'run';

        return ['steps' => 1, 'complete' => true];
    }

    public function pending(): array
    {
        return array_fill(0, $this->pending, '0001_core_foundation');
    }
}
