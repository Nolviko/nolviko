<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * A migration with given statements, for registry and runner tests.
 */
final class FixtureMigration implements Migration
{
    /**
     * @param list<string> $statements Used for every backend (portable SQL; `SELECT 1` as a no-op step).
     */
    public function __construct(
        private readonly string $id,
        private readonly array $statements = ['SELECT 1'],
        private readonly MigrationPhase $phase = MigrationPhase::Expand,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function phase(): MigrationPhase
    {
        return $this->phase;
    }

    public function statements(string $backend): array
    {
        // A no-op step: MySQL's `DO n` returns no result set (a `SELECT` would stay unread on that connection);
        // PostgreSQL has no `DO n`, so it gets `SELECT n`.
        return $backend === 'postgresql' ? $this->statements : array_map(
            static fn(string $sql): string => (string) preg_replace('/^SELECT (\d+)$/', 'DO $1', $sql),
            $this->statements,
        );
    }
}
