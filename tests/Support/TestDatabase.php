<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Nolviko\Core\Database\Migration\MigrationRegistry;
use Nolviko\Core\Database\Migration\MigrationRunner;
use Nolviko\Core\Installer\FreshInstallDefaults;

/**
 * Helpers for Integration tests on the throw-away compose database.
 */
final class TestDatabase
{
    /** Created with every test schema: a database holding it was made by these tests, never by an installation. */
    public const MARKER = 'zz_nolviko_test_marker';

    /** @var array<string, list<array<string, mixed>>>|null Seed rows of every table after a fresh migration. */
    private static ?array $seed = null;

    /** @var array<string, int> AUTO_INCREMENT of every table right after the fresh migration. */
    private static array $increments = [];

    private static ?string $fingerprint = null;

    /**
     * The backend under test: `FORUM_TEST_DB_DRIVER` (`mysql` by default, `mariadb` or `postgresql`).
     */
    public static function driver(): string
    {
        $driver = getenv('FORUM_TEST_DB_DRIVER');

        return is_string($driver) && $driver !== '' ? $driver : 'mysql';
    }

    /**
     * Whether the database may be emptied: this process prepared it already, or it holds no installed forum
     * (`settings` rows). Tests call this before `reset()` and fail otherwise.
     */
    public static function isDisposable(Connection $connection): bool
    {
        $manager = $connection->createSchemaManager();

        if (self::$fingerprint !== null || $manager->tablesExist([self::MARKER])) {
            return true;
        }

        return !$manager->tablesExist(['settings'])
            || (int) $connection->fetchOne('SELECT COUNT(*) FROM settings') === 0;
    }

    /**
     * Drops every table of the test database (foreign keys off meanwhile), so tests need no table lists that
     * go stale when a migration is added. Callers must first refuse an installed forum (`settings` rows).
     */
    public static function dropAll(Connection $connection): void
    {
        if (self::postgres()) {
            // The whole schema, so sequences and the clock function go too (the test role owns the database).
            $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
            $connection->executeStatement('CREATE SCHEMA public');
            self::$fingerprint = null;

            return;
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($connection->createSchemaManager()->listTableNames() as $table) {
                $connection->executeStatement('DROP TABLE IF EXISTS ' . $connection->quoteIdentifier($table));
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        self::$fingerprint = null;
    }

    /**
     * The freshly migrated core schema with only its seed rows, as `dropAll()` plus a full migration would leave
     * it. The first call of a process migrates and keeps the seed rows; later calls only empty the tables,
     * restore those rows and reset AUTO_INCREMENT, unless the schema differs (another test dropped or changed
     * tables), which triggers the full rebuild again. Callers must first refuse an installed forum.
     */
    public static function reset(Connection $connection): void
    {
        if (
            self::$seed === null
            || self::$fingerprint === null
            || self::fingerprint($connection) !== self::$fingerprint
        ) {
            self::rebuild($connection);

            return;
        }

        if (self::postgres()) {
            self::resetPostgres($connection);

            return;
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (self::$seed as $table => $rows) {
                $quoted = $connection->quoteIdentifier($table);
                $connection->executeStatement('DELETE FROM ' . $quoted);

                foreach ($rows as $row) {
                    $connection->insert($quoted, $row);
                }
            }

            foreach (self::increments($connection) as $table => $next) {
                if ($next !== (self::$increments[$table] ?? $next)) {
                    $connection->executeStatement(
                        'ALTER TABLE ' . $connection->quoteIdentifier($table) . ' AUTO_INCREMENT = '
                            . self::$increments[$table],
                    );
                }
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * The end of a test: the schema stays for the next `reset()`; the process drops it when it exits.
     */
    public static function release(Connection $connection): void
    {
        static $registered = false;

        if (!$registered) {
            $registered = true;
            register_shutdown_function(static function () use ($connection): void {
                if (self::$fingerprint !== null) {
                    self::dropAll($connection);
                }
            });
        }
    }

    private static function rebuild(Connection $connection): void
    {
        self::dropAll($connection);
        (new MigrationRunner($connection, MigrationRegistry::core(), self::driver()))->run();
        // A test database is a fresh install (2026-11-16).
        FreshInstallDefaults::apply($connection);
        $connection->executeStatement('CREATE TABLE ' . self::MARKER . ' (id INT NOT NULL PRIMARY KEY)');
        $seed = [];

        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $seed[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table));

            // PostgreSQL refuses values for generated columns; they are computed again on insert.
            foreach (
                self::postgres() ? $connection->fetchFirstColumn(
                    'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() '
                    . "AND table_name = ? AND is_generated = 'ALWAYS'",
                    [$table],
                ) : [] as $generated
            ) {
                foreach ($seed[$table] as $index => $row) {
                    unset($seed[$table][$index][$generated]);
                }
            }
        }

        self::$seed = $seed;
        self::$increments = self::increments($connection);
        self::$fingerprint = self::fingerprint($connection);
    }

    /**
     * Columns and indexes of every table, so a changed schema is noticed.
     */
    private static function fingerprint(Connection $connection): string
    {
        if (self::postgres()) {
            $columns = $connection->fetchFirstColumn(
                "SELECT table_name || '.' || column_name || ':' || data_type FROM information_schema.columns"
                    . ' WHERE table_schema = current_schema() ORDER BY table_name, ordinal_position',
            );
            $indexes = $connection->fetchFirstColumn(
                "SELECT tablename || '.' || indexname || ':' || indexdef FROM pg_indexes"
                    . ' WHERE schemaname = current_schema() ORDER BY tablename, indexname',
            );

            return hash('sha256', implode("
", $columns) . "
--
" . implode("
", $indexes));
        }

        $columns = $connection->fetchFirstColumn(
            'SELECT CONCAT(TABLE_NAME, ".", COLUMN_NAME, ":", COLUMN_TYPE) FROM information_schema.COLUMNS'
                . ' WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION',
        );
        $indexes = $connection->fetchFirstColumn(
            'SELECT CONCAT(TABLE_NAME, ".", INDEX_NAME, ":", COLUMN_NAME) FROM information_schema.STATISTICS'
                . ' WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
        );

        return hash('sha256', implode("\n", $columns) . "\n--\n" . implode("\n", $indexes));
    }

    /**
     * @return array<string, int> Next AUTO_INCREMENT value per table that has one (fresh, not cached statistics).
     */
    private static function increments(Connection $connection): array
    {
        if (self::postgres()) {
            // Sequence name => the value the next nextval() returns.
            $rows = $connection->fetchAllAssociative(
                'SELECT sequencename, last_value, start_value FROM pg_sequences WHERE schemaname = current_schema()',
            );
            $next = [];

            // An unused sequence (seeded with explicit ids only) still starts at its START WITH value.
            foreach ($rows as $row) {
                $next[(string) $row['sequencename']] = $row['last_value'] === null
                    ? (int) $row['start_value'] : (int) $row['last_value'] + 1;
            }

            return $next;
        }

        if (self::driver() === 'mysql') {
            // MySQL caches table statistics for a day by default; MariaDB reads them live.
            $connection->executeStatement('SET SESSION information_schema_stats_expiry = 0');
        }

        $rows = $connection->fetchAllKeyValue(
            'SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES'
                . ' WHERE TABLE_SCHEMA = DATABASE() AND AUTO_INCREMENT IS NOT NULL',
        );

        return array_map('intval', $rows);
    }

    /**
     * Fresh planner statistics for the given tables (comma-separated names): MySQL `ANALYZE TABLE a, b`,
     * PostgreSQL `ANALYZE a, b`.
     */
    public static function analyze(Connection $connection, string $tables): void
    {
        if (preg_match('/^[a-z_]+(, [a-z_]+)*$/', $tables) !== 1) {
            throw new InvalidArgumentException('tables');
        }

        // MySQL answers with a result set, which must be read.
        $connection->fetchAllAssociative((self::postgres() ? 'ANALYZE ' : 'ANALYZE TABLE ') . $tables);
    }

    /**
     * PostgreSQL plan facts for index-use checks (the MySQL tests read `EXPLAIN` key/Extra): the indexes the
     * plan reads and whether it sorts. Primary keys are reported as `PRIMARY`, like MySQL.
     *
     * @param list<mixed> $parameters
     * @param array<int, mixed> $types
     *
     * @return array{indexes: list<string>, aliases: array<string, string>, sort: bool,
     *     plan: string}
     */
    public static function postgresPlan(
        Connection $connection,
        string $sql,
        array $parameters,
        array $types = [],
    ): array {
        $json = (string) $connection->fetchOne('EXPLAIN (FORMAT JSON) ' . $sql, $parameters, $types);
        $indexes = [];
        $aliases = [];
        $sort = false;
        $walk = static function (array $node, ?string $alias) use (&$walk, &$indexes, &$aliases, &$sort): void {
            // A bitmap heap scan names the table; its bitmap index scans below name the index.
            $alias = isset($node['Alias']) ? (string) $node['Alias'] : $alias;

            if (isset($node['Index Name'])) {
                $name = (string) $node['Index Name'];
                $name = str_ends_with($name, '_pkey') ? 'PRIMARY' : $name;
                $indexes[] = $name;

                if ($alias !== null) {
                    $aliases[$alias] ??= $name;
                }
            }

            $sort = $sort || in_array($node['Node Type'] ?? '', ['Sort', 'Incremental Sort'], true);

            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child, $alias);
            }
        };
        $walk(json_decode($json, true, 512, JSON_THROW_ON_ERROR)[0]['Plan'], null);

        return ['indexes' => array_values(array_unique($indexes)), 'aliases' => $aliases, 'sort' => $sort,
            'plan' => $json];
    }

    /**
     * The `EXPLAIN` row of one table (alias) on either backend, in MySQL's shape: `key` (the index read),
     * `Extra` (contains `filesort` when anything is sorted) and `rows`. On PostgreSQL it comes from postgresPlan.
     *
     * @param list<mixed> $parameters
     * @param array<int, mixed> $types
     *
     * @return array<string, mixed>
     */
    public static function explainRow(
        Connection $connection,
        string $sql,
        array $parameters,
        string $table,
        array $types = [],
    ): array {
        if (self::postgres()) {
            $plan = self::postgresPlan($connection, $sql, $parameters, $types);

            return ['table' => $table, 'key' => $plan['aliases'][$table] ?? null,
                'Extra' => $plan['sort'] ? 'Using filesort' : '', 'rows' => 0, 'plan' => $plan['plan']];
        }

        foreach ($connection->fetchAllAssociative('EXPLAIN ' . $sql, $parameters, $types) as $row) {
            if ($row['table'] === $table) {
                return $row;
            }
        }

        throw new InvalidArgumentException('no plan row for ' . $table);
    }

    private static function postgres(): bool
    {
        return self::driver() === 'postgresql';
    }

    /**
     * PostgreSQL reset: all tables emptied in one TRUNCATE, seed rows back with triggers (foreign keys) off for
     * this session, sequences back to their values after the fresh migration.
     */
    private static function resetPostgres(Connection $connection): void
    {
        $tables = array_map(static fn(string $table): string => $connection->quoteIdentifier($table), array_keys(
            self::$seed ?? [],
        ));
        $connection->executeStatement('TRUNCATE ' . implode(', ', $tables) . ' CASCADE');
        $connection->executeStatement("SET session_replication_role = 'replica'");

        try {
            foreach (self::$seed ?? [] as $table => $rows) {
                foreach ($rows as $row) {
                    $connection->insert($connection->quoteIdentifier($table), $row);
                }
            }
        } finally {
            $connection->executeStatement("SET session_replication_role = 'origin'");
        }

        foreach (self::$increments as $sequence => $next) {
            $connection->executeStatement('SELECT setval(?, ?, false)', [$sequence, $next]);
        }
    }
}
