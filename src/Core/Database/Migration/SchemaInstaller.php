<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nolviko\Core\Installer\SchemaMigrator;

/**
 * The installer's migration step (SchemaMigrator): applies the core migrations one checkpointed step at a
 * time until done or until the time budget is used, so a request stays well inside `max_execution_time=30`.
 * Repeating a request (refresh, double submit, a second browser) continues from the last checkpoint; the
 * migration lease serialises them.
 */
final class SchemaInstaller implements SchemaMigrator
{
    /** Seconds of migration work per request, leaving room for bootstrap and rendering. */
    public const TIME_BUDGET_SECONDS = 10.0;


    private readonly Closure $clock;

    /**
     * @param Closure(): Connection $connection Lazy: only a configured site has one.
     * @param (Closure(): float)|null $clock Seconds; null means microtime(true).
     * @param list<Closure(Connection): mixed> $afterComplete Idempotent syncs run whenever a batch ends with
     *     every migration applied (for example the permission catalog).
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly MigrationRegistry $registry,
        ?Closure $clock = null,
        private readonly float $budget = self::TIME_BUDGET_SECONDS,
        private readonly array $afterComplete = [],
    ) {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    /**
     * @return array{steps: int, complete: bool}
     *
     * @throws MigrationException With a diagnostic ID; the next request retries the failed step.
     */
    public function run(): array
    {
        $runner = $this->runner();
        $started = ($this->clock)();
        $report = $runner->run(continue: fn(): bool => ($this->clock)() - $started < $this->budget);

        if ($report->complete) {
            foreach ($this->afterComplete as $sync) {
                $sync(($this->connection)());
            }
        }

        return ['steps' => $report->steps, 'complete' => $report->complete];
    }

    /**
     * @return list<string> Migration IDs not applied yet.
     */
    public function pending(): array
    {
        return $this->runner()->pending();
    }

    private function runner(): MigrationRunner
    {
        $connection = ($this->connection)();

        // MariaDB is told apart by DBAL from the server version.
        $platform = $connection->getDatabasePlatform();
        $backend = match (true) {
            $platform instanceof MariaDBPlatform => MariaDbDialect::BACKEND,
            $platform instanceof PostgreSQLPlatform => PostgresDialect::BACKEND,
            default => 'mysql',
        };

        return new MigrationRunner($connection, $this->registry, $backend);
    }
}
