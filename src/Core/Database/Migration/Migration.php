<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration;

/**
 * One schema change. Each statement is one checkpointed step: MySQL/MariaDB DDL commits implicitly, so a step
 * must be safe to repeat after it was interrupted, or complete as a single atomic statement.
 *
 * Applied migrations are immutable: their checksum (id, phase, statements of the backend) is recorded, and a
 * change refuses to run. Fix forward with a new migration.
 */
interface Migration
{
    /** Sortable, stable ID: four digits, underscore, lowercase name (`0001_core_foundation`). */
    public function id(): string;

    public function phase(): MigrationPhase;

    /**
     * @param string $backend Runtime-policy driver name (`mysql`, `mariadb`, `postgresql`).
     *
     * @return list<string> Explicit backend DDL/DML, one step each.
     *
     * @throws MigrationException `migration_backend_unsupported:<backend>` when not written for that backend yet.
     */
    public function statements(string $backend): array;
}
