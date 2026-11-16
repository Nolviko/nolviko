<?php

declare(strict_types=1);

namespace Nolviko\Tests\Contract;

use Nolviko\Core\Database\Migration\ExpandOnlyPolicy;
use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Every core migration that a released version did not have must be safe for the unattended updater
 * (ExpandOnlyPolicy: create tables, add columns, indexes and foreign keys; no data changes), on every database
 * backend (2026-11-16, found by the beta.1 -> beta.2 upgrade drill). A default that must change only for new sites
 * goes into Core\Installer\FreshInstallDefaults instead of a migration.
 */
final class UpdateSafeMigrationsTest extends TestCase
{
    /** The last migration of the first release (0.1.0-beta.1); later ones run during updates. */
    private const LAST_RELEASED = '0043_seo_sitemaps';

    public function testMigrationsAfterTheLastReleaseAreUpdateSafe(): void
    {
        $checked = 0;
        $problems = [];
        foreach ($this->migrations() as $migration) {
            if (strcmp($migration->id(), self::LAST_RELEASED) <= 0) {
                continue;
            }
            foreach (['mysql', 'postgresql'] as $backend) {
                try {
                    ExpandOnlyPolicy::assertAllowed($migration, $backend);
                } catch (MigrationException $refused) {
                    $problems[] = $migration->id() . ' ' . $backend . ': ' . $refused->getMessage();
                }
            }
            ++$checked;
        }

        self::assertGreaterThan(0, $checked);
        self::assertSame([], $problems, 'unattended updates would stop at these migrations');
    }

    public function testThePolicyStillRefusesDataChanges(): void
    {
        foreach (
            [
                "UPDATE usergroup_permissions SET value = '0'",
                'ALTER TABLE threads ADD COLUMN a INT, ADD COLUMN b INT',
                'ALTER TABLE threads DROP COLUMN prefix_id',
                'DELETE FROM threads',
            ] as $sql
        ) {
            try {
                ExpandOnlyPolicy::assertAllowed(self::migration($sql), 'mysql');
                self::fail('allowed: ' . $sql);
            } catch (MigrationException) {
                $this->addToAssertionCount(1);
            }
        }

        ExpandOnlyPolicy::assertAllowed(self::migration(
            'ALTER TABLE threads ADD CONSTRAINT fk_x FOREIGN KEY (prefix_id) REFERENCES thread_prefixes (id) '
                . 'ON DELETE SET NULL',
        ), 'mysql');
    }

    /** @return list<Migration> */
    private function migrations(): array
    {
        $registry = MigrationRegistry::core();

        return (new ReflectionProperty($registry, 'migrations'))->getValue($registry);
    }

    private static function migration(string $sql): Migration
    {
        return new class ($sql) implements Migration {
            public function __construct(private readonly string $sql)
            {
            }

            public function id(): string
            {
                return '9999_policy_probe';
            }

            public function phase(): \Nolviko\Core\Database\Migration\MigrationPhase
            {
                return \Nolviko\Core\Database\Migration\MigrationPhase::Expand;
            }

            public function statements(string $backend): array
            {
                return [$this->sql];
            }
        };
    }
}
