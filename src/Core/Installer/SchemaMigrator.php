<?php

declare(strict_types=1);

namespace Nolviko\Core\Installer;

use Nolviko\Core\Database\Migration\MigrationException;

/**
 * What the installer's migration step needs: run a time-bounded batch, and list what is still pending.
 */
interface SchemaMigrator
{
    /**
     * @return array{steps: int, complete: bool}
     *
     * @throws MigrationException
     */
    public function run(): array;

    /**
     * @return list<string>
     */
    public function pending(): array;
}
