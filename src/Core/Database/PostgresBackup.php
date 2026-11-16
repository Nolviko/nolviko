<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nolviko\Core\Database\Migration\PostgresDialect;
use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Core\Updates\BackupRowCodec;
use RuntimeException;
use Throwable;

/**
 * Bounded PostgreSQL snapshot (2026-11-14), the counterpart of MySqlBackup with the same manifest format and
 * budgets. The rows are read in one REPEATABLE READ, READ ONLY transaction, so every table comes from the same
 * snapshot without locking writers out. PostgreSQL has no SHOW CREATE TABLE: the DDL is rebuilt from the
 * catalogs (column types and collations, identity and stored generated columns, defaults, primary/unique/check
 * constraints inline; other indexes and foreign keys as separate statements run after the rows, so a restore
 * needs no disabled constraint checks). Values travel as their text form (`bytea` as `\x...` hex), which
 * PostgreSQL parses back with the column type on insert. Views, triggers and functions other than the
 * schema's clock function are refused, like routines on MySQL.
 */
final class PostgresBackup implements DatabaseBackup
{
    public const MAX_BYTES = MySqlBackup::MAX_BYTES;

    public const MAX_ROWS = MySqlBackup::MAX_ROWS;

    public function __construct(private readonly Connection $connection)
    {
        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            throw new RuntimeException('backup_backend_unsupported');
        }
    }

    public function export(BackupChunkStore $store, Closure $guard, ?Closure $captureFiles = null): array
    {
        if ($this->connection->isTransactionActive()) {
            throw new RuntimeException('backup_transaction_active');
        }

        $guard();
        $tables = $this->tables();

        if ($tables === [] || count($tables) > 256) {
            throw new RuntimeException('backup_table_budget');
        }

        $this->refuseObjects();
        $bytes = 0;
        $rows = 0;
        $deadline = microtime(true) + 10;
        $manifest = ['format' => 1, 'platform' => $this->connection->getDatabasePlatform()::class, 'tables' => [],
            'after' => [], 'sequences' => []];
        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $this->connection->executeStatement("SET LOCAL lock_timeout = '2s'");
            // The snapshot is taken by the first query, not by BEGIN: pin it before any guard call can run.
            $schema = (string) $this->connection->fetchOne('SELECT current_schema()');
            $indexes = [];
            $keys = [];

            foreach ($tables as $table) {
                $guard();
                [$entry, $tableIndexes, $tableKeys, $sequences] = $this->describe($table, $schema);
                $indexes = [...$indexes, ...$tableIndexes];
                $keys = [...$keys, ...$tableKeys];
                $manifest['sequences'] = [...$manifest['sequences'], ...$sequences];
                $result = $this->connection->executeQuery('SELECT ' . implode(', ', array_map(
                    fn(string $name): string => 'left(' . $this->quote($name) . '::text, 524289)',
                    $entry['columns'],
                )) . ' FROM ' . $this->quote($table));
                $buffer = '';

                try {
                    while (($row = $result->fetchNumeric()) !== false) {
                        $line = BackupRowCodec::encode(array_map(
                            static fn(mixed $value): ?string => $value === null ? null : (string) $value,
                            $row,
                        ));
                        $bytes += strlen($line);
                        ++$rows;
                        ++$entry['rows'];
                        $entry['bytes'] += strlen($line);

                        if ($bytes > self::MAX_BYTES || $rows > self::MAX_ROWS || microtime(true) > $deadline) {
                            throw new RuntimeException('backup_snapshot_budget');
                        }

                        if (strlen($buffer) + strlen($line) > BackupChunkStore::MAX_CHUNK_BYTES) {
                            $entry['chunks'][] = self::save($store, $buffer);
                            $buffer = '';
                        }

                        $buffer .= $line;
                    }
                } finally {
                    $result->free();
                }

                if ($buffer !== '') {
                    $entry['chunks'][] = self::save($store, $buffer);
                }

                $manifest['tables'][] = $entry;
            }

            if ($tables !== $this->tables() || microtime(true) > $deadline) {
                throw new RuntimeException('backup_snapshot_changed');
            }

            $this->connection->commit();
            // All indexes first: a foreign key may need another table's unique index.
            $manifest['after'] = [...$indexes, ...$keys];
        } catch (Throwable $failure) {
            $this->connection->rollBack();

            throw $failure;
        }

        $manifest['files'] = $captureFiles === null ? null : $captureFiles();

        if (microtime(true) > $deadline) {
            throw new RuntimeException('backup_snapshot_budget');
        }

        $guard();
        $manifest['rows'] = $rows;
        $manifest['bytes'] = $bytes;

        return self::save($store, json_encode($manifest, JSON_THROW_ON_ERROR));
    }

    public function restore(BackupChunkStore $store, array $handle): void
    {
        if ($this->connection->isTransactionActive() || $this->tables() !== []) {
            throw new RuntimeException('backup_restore_requires_empty_database');
        }

        $manifest = json_decode($store->read($handle['id'], $handle['digest']), true, 32, JSON_THROW_ON_ERROR);

        if (
            ($manifest['format'] ?? null) !== 1
            || ($manifest['platform'] ?? null) !== $this->connection->getDatabasePlatform()::class
            || !is_array($manifest['tables'] ?? null)
            || !is_array($manifest['after'] ?? null)
            || !is_array($manifest['sequences'] ?? null)
        ) {
            throw new RuntimeException('backup_manifest_invalid');
        }

        // Verify every byte before creating any table. The handle/digest must come from trusted private state.
        foreach ($manifest['tables'] as $entry) {
            $this->quote($entry['name']);
            $rows = 0;
            $bytes = 0;

            foreach ($entry['chunks'] as $chunk) {
                foreach (self::lines($store->read($chunk['id'], $chunk['digest'])) as $line) {
                    BackupRowCodec::decode($line, count($entry['columns']));
                    ++$rows;
                    $bytes += strlen($line);
                }
            }

            if ($rows !== $entry['rows'] || $bytes !== $entry['bytes']) {
                throw new RuntimeException('backup_table_inventory_invalid');
            }
        }

        // DDL is transactional in PostgreSQL: a failed restore leaves the database empty again.
        $this->connection->transactional(function (Connection $connection) use ($manifest, $store): void {
            $connection->executeStatement(PostgresDialect::CLOCK_FUNCTION);

            foreach ($manifest['tables'] as $entry) {
                $connection->executeStatement($entry['ddl']);
                $sql = 'INSERT INTO ' . $this->quote($entry['name']) . ' (' . implode(', ', array_map(
                    fn(string $name): string => $this->quote($name),
                    $entry['columns'],
                )) . ') OVERRIDING SYSTEM VALUE VALUES ('
                    . implode(', ', array_fill(0, count($entry['columns']), '?')) . ')';

                foreach ($entry['chunks'] as $chunk) {
                    foreach (self::lines($store->read($chunk['id'], $chunk['digest'])) as $line) {
                        $connection->executeStatement($sql, BackupRowCodec::decode($line, count($entry['columns'])));
                    }
                }
            }

            foreach ($manifest['sequences'] as [$table, $column, $value]) {
                $connection->executeStatement(
                    'SELECT setval(pg_get_serial_sequence(?, ?), ?, true)',
                    [$this->quote($table), $column, (int) $value],
                );
            }

            foreach ($manifest['after'] as $statement) {
                $connection->executeStatement((string) $statement);
            }
        });
    }

    /**
     * The table's manifest entry with its CREATE TABLE, its other indexes and its foreign keys (both run after
     * the rows) and its identity sequence positions.
     *
     * @return array{0: array<string, mixed>, 1: list<string>, 2: list<string>, 3: list<array{0: string, 1: string,
     *     2: int}>}
     */
    private function describe(string $table, string $schema): array
    {
        $quoted = $this->quote($table);
        $columns = $this->connection->fetchAllAssociative(
            'SELECT a.attname AS name, format_type(a.atttypid, a.atttypmod) AS type, a.attnotnull AS not_null, '
                . 'pg_get_expr(d.adbin, d.adrelid) AS expression, a.attidentity AS identity, '
                . 'a.attgenerated AS generated, CASE WHEN a.attcollation <> t.typcollation THEN c.collname END '
                . 'AS collation FROM pg_attribute a JOIN pg_type t ON t.oid = a.atttypid '
                . 'LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum '
                . 'LEFT JOIN pg_collation c ON c.oid = a.attcollation '
                . 'WHERE a.attrelid = CAST(? AS regclass) AND a.attnum > 0 AND NOT a.attisdropped ORDER BY a.attnum',
            [$quoted],
        );

        if ($columns === [] || count($columns) > 64) {
            throw new RuntimeException('backup_column_budget');
        }

        $definitions = [];
        $names = [];
        $sequences = [];

        foreach ($columns as $column) {
            $name = (string) $column['name'];
            $definition = $this->quote($name) . ' ' . $column['type'];
            $definition .= $column['collation'] !== null ? ' COLLATE "' . $column['collation'] . '"' : '';

            if ($column['generated'] === 's') {
                $definitions[] = $definition . ' GENERATED ALWAYS AS (' . $column['expression'] . ') STORED';

                continue;
            }

            $names[] = $name;

            if ($column['identity'] === 'a' || $column['identity'] === 'd') {
                $definition .= ' GENERATED ' . ($column['identity'] === 'a' ? 'ALWAYS' : 'BY DEFAULT') . ' AS IDENTITY';
                $last = $this->connection->fetchOne(
                    'SELECT last_value FROM pg_sequences WHERE schemaname || \'.\' || sequencename = '
                        . 'pg_get_serial_sequence(?, ?)',
                    [$quoted, $name],
                );

                if ($last !== null && $last !== false) {
                    $sequences[] = [$table, $name, (int) $last];
                }
            } elseif ($column['expression'] !== null) {
                $definition .= ' DEFAULT ' . $column['expression'];
            }

            $notNull = $column['not_null'] === true || $column['not_null'] === 't';
            $definitions[] = $definition . ($notNull ? ' NOT NULL' : '');
        }

        $keys = [];

        foreach (
            $this->connection->fetchAllAssociative(
                'SELECT conname, contype, pg_get_constraintdef(oid) AS definition FROM pg_constraint '
                . 'WHERE conrelid = CAST(? AS regclass) ORDER BY contype, conname',
                [$quoted],
            ) as $constraint
        ) {
            $sql = 'CONSTRAINT ' . $this->quote((string) $constraint['conname']) . ' ' . $constraint['definition'];

            if ($constraint['contype'] === 'f') {
                $keys[] = 'ALTER TABLE ' . $quoted . ' ADD ' . $sql;
            } elseif (in_array($constraint['contype'], ['p', 'u', 'c'], true)) {
                $definitions[] = $sql;
            }
        }

        $indexes = [];

        foreach (
            $this->connection->fetchFirstColumn(
                'SELECT pg_get_indexdef(i.indexrelid) FROM pg_index i WHERE i.indrelid = CAST(? AS regclass) '
                . 'AND NOT EXISTS (SELECT 1 FROM pg_constraint c WHERE c.conindid = i.indexrelid '
                . "AND c.contype IN ('p', 'u', 'x')) "
                . 'ORDER BY i.indexrelid',
                [$quoted],
            ) as $index
        ) {
            // Restore into whatever schema the target connection uses.
            $indexes[] = str_replace(' ON ' . $schema . '.', ' ON ', (string) $index);
        }

        return [['name' => $table, 'columns' => $names, 'ddl' => 'CREATE TABLE ' . $quoted . ' ('
            . implode(', ', $definitions) . ')', 'rows' => 0, 'bytes' => 0, 'chunks' => []], $indexes, $keys,
            $sequences];
    }

    /** Views, triggers and functions other than the clock function have no backup format. */
    private function refuseObjects(): void
    {
        if (
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid '
                    . 'WHERE NOT t.tgisinternal AND c.relnamespace = CAST(current_schema() AS regnamespace)',
            ) > 0
        ) {
            throw new RuntimeException('backup_trigger_unsupported');
        }

        if (
            (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM pg_proc WHERE pronamespace = CAST(current_schema() AS regnamespace) "
                    . "AND proname <> 'utc_timestamp'",
            ) > 0
        ) {
            throw new RuntimeException('backup_routine_unsupported');
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $rows = $this->connection->fetchAllNumeric(
            'SELECT table_name, table_type FROM information_schema.tables WHERE table_schema = current_schema() '
                . 'ORDER BY table_name',
        );
        $tables = [];

        foreach ($rows as $row) {
            if ($row[1] !== 'BASE TABLE') {
                throw new RuntimeException('backup_view_unsupported');
            }

            $tables[] = (string) $row[0];
        }

        sort($tables, SORT_STRING);

        return $tables;
    }

    private function quote(string $name): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) !== 1) {
            throw new RuntimeException('backup_identifier_invalid');
        }

        return $this->connection->quoteIdentifier($name);
    }

    /**
     * @return array{id: string, digest: string}
     */
    private static function save(BackupChunkStore $store, string $bytes): array
    {
        $id = bin2hex(random_bytes(16));

        return ['id' => $id, 'digest' => $store->put($id, $bytes)];
    }

    /**
     * @return list<string>
     */
    private static function lines(string $bytes): array
    {
        if (!str_ends_with($bytes, "\n")) {
            throw new RuntimeException('backup_rows_incomplete');
        }

        return array_map(static fn(string $line): string => $line . "\n", explode("\n", substr($bytes, 0, -1)));
    }
}
