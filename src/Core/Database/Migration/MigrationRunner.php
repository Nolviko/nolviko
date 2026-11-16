<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Nolviko\Core\Database\Migration\Core\CoreFoundation;

/**
 * Applies pending migrations in bounded, resumable steps (ADR-026), one web request at a time.
 *
 * Order per run: create the runner's tables if missing, take the lease, verify the history (unknown IDs and
 * changed checksums refuse everything), then execute steps from each migration's recorded checkpoint. After
 * every step the checkpoint is stored, so a request that dies (timeout, crash, failed statement) resumes on the
 * next call without repeating completed steps.
 */
final class MigrationRunner
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_APPLIED = 'applied';

    private const TIME_FORMAT = 'Y-m-d H:i:s.u';

    private readonly Closure $clock;

    /**
     * @param string $backend Runtime-policy driver name of the connection.
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the system clock.
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly MigrationRegistry $registry,
        private readonly string $backend,
        ?Closure $clock = null,
        private readonly int $leaseSeconds = MigrationLease::DEFAULT_SECONDS,
        private readonly ?Closure $guard = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable('now');
    }

    /**
     * @param int $maxSteps Upper bound for this call (a web request runs a few, then reloads).
     * @param bool $allowContract Only an explicit admin action may run `contract` migrations.
     * @param (Closure(): bool)|null $continue Time-budget check between durable steps, within the same lease.
     *
     * @throws MigrationException With a diagnostic ID; nothing after a failed step runs.
     */
    public function run(
        int $maxSteps = PHP_INT_MAX,
        bool $allowContract = false,
        ?Closure $continue = null,
    ): MigrationReport {
        $this->assertGuard();
        $this->bootstrap();
        $lease = new MigrationLease($this->connection, $this->clock, $this->leaseSeconds);
        $lease->acquire();

        try {
            return $this->apply($lease, $this->history(), $maxSteps, $allowContract, $continue);
        } finally {
            $lease->release();
        }
    }

    /**
     * IDs of registered migrations not yet applied (installer/updater status; read-only, no lease).
     *
     * @return list<string>
     */
    public function pending(): array
    {
        $history = $this->tableExists() ? $this->history() : [];
        $pending = [];

        foreach ($this->registry->migrations as $migration) {
            if (($history[$migration->id()]['status'] ?? null) !== self::STATUS_APPLIED) {
                $pending[] = $migration->id();
            }
        }

        return $pending;
    }

    /**
     * @param array<string, array{checksum: string, status: string, completed_steps: int}> $history
     */
    private function apply(
        MigrationLease $lease,
        array $history,
        int $maxSteps,
        bool $allowContract,
        ?Closure $continue,
    ): MigrationReport {
        $applied = [];
        $steps = 0;

        foreach ($this->registry->migrations as $migration) {
            $id = $migration->id();
            $row = $history[$id] ?? null;

            if ($row !== null && $row['status'] === self::STATUS_APPLIED) {
                continue;
            }

            if ($migration->phase() === MigrationPhase::Contract && !$allowContract) {
                throw new MigrationException('migration_contract_requires_admin:' . $id);
            }

            $statements = MigrationRegistry::statements($migration, $this->backend);

            if ($row === null) {
                $this->connection->insert('migration_history', [
                    'id' => $id,
                    'checksum' => MigrationRegistry::checksum($migration, $this->backend),
                    'phase' => $migration->phase()->value,
                    'status' => self::STATUS_RUNNING,
                    'completed_steps' => 0,
                    'started_at' => $this->now(),
                ]);
            }

            for ($step = $row['completed_steps'] ?? 0; $step < count($statements); ++$step) {
                if ($steps >= $maxSteps || ($steps > 0 && $continue !== null && !$continue())) {
                    return new MigrationReport($applied, $steps, false);
                }

                $lease->renew();
                $this->execute($id, $step, $statements[$step]);
                ++$steps;
            }

            $this->connection->update(
                'migration_history',
                ['status' => self::STATUS_APPLIED, 'applied_at' => $this->now()],
                ['id' => $id],
            );
            $applied[] = $id;
        }

        return new MigrationReport($applied, $steps, true);
    }

    private function execute(string $id, int $step, string $statement): void
    {
        $this->assertGuard();
        try {
            $this->connection->executeStatement($statement);
        } catch (DbalException $failure) {
            throw new MigrationException('migration_step_failed:' . $id . ':' . $step, $failure);
        }

        $this->assertGuard();
        $this->connection->update('migration_history', ['completed_steps' => $step + 1], ['id' => $id]);
    }

    /**
     * Creates `migration_history` and `migration_leases` (idempotent) so the lease can be taken at all.
     */
    private function bootstrap(): void
    {
        if ($this->connection->createSchemaManager()->tablesExist(['migration_history', 'migration_leases'])) {
            return;
        }

        $first = $this->registry->migrations[0] ?? null;

        if (!$first instanceof CoreFoundation) {
            throw new MigrationException('migration_registry_invalid');
        }

        $bootstrap = array_slice(
            MigrationRegistry::statements($first, $this->backend),
            0,
            CoreFoundation::BOOTSTRAP_STEPS,
        );

        foreach ($bootstrap as $statement) {
            $this->connection->executeStatement($statement);
        }
    }

    /**
     * @return array<string, array{checksum: string, status: string, completed_steps: int}>
     *
     * @throws MigrationException `migration_unknown:<id>` (database newer than the code, or a removed
     *     migration) or `migration_checksum_mismatch:<id>` (an applied or started migration was edited).
     */
    private function history(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, checksum, status, completed_steps FROM migration_history ORDER BY id',
        );
        $history = [];

        foreach ($rows as $row) {
            $id = (string) $row['id'];
            $migration = $this->registry->find($id) ?? throw new MigrationException('migration_unknown:' . $id);

            if (!hash_equals(MigrationRegistry::checksum($migration, $this->backend), (string) $row['checksum'])) {
                throw new MigrationException('migration_checksum_mismatch:' . $id);
            }

            $history[$id] = [
                'checksum' => (string) $row['checksum'],
                'status' => (string) $row['status'],
                'completed_steps' => (int) $row['completed_steps'],
            ];
        }

        return $history;
    }

    private function tableExists(): bool
    {
        return $this->connection->createSchemaManager()->tablesExist(['migration_history']);
    }

    private function assertGuard(): void
    {
        if ($this->guard !== null) {
            ($this->guard)();
        }
    }

    private function now(): string
    {
        return ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format(self::TIME_FORMAT);
    }
}
