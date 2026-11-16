<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nolviko\Core\Updates\BackupChunkStore;
use Nolviko\Core\Updates\BackupRowCodec;
use PDO;
use RuntimeException;

/**
 * Bounded MySQL-family snapshot. No shell, automatic restore, public endpoint or cross-request snapshot.
 */
final class MySqlBackup implements DatabaseBackup
{
    public const MAX_BYTES = 33554432;

    public const MAX_ROWS = 100000;

    public function __construct(private readonly Connection $connection)
    {
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            throw new RuntimeException('backup_backend_unsupported');
        }
    }

    /**
     * The guard must verify an owned unexpired update lease and maintenance/quiescent files; it must not write SQL.
     *
     * @return array{id: string, digest: string}
     */
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

        if ($this->connection->fetchAllAssociative('SHOW TRIGGERS') !== []) {
            throw new RuntimeException('backup_trigger_unsupported');
        }

        foreach (['PROCEDURE', 'FUNCTION'] as $kind) {
            if ($this->connection->fetchAllAssociative('SHOW ' . $kind . ' STATUS WHERE Db = DATABASE()') !== []) {
                throw new RuntimeException('backup_routine_unsupported');
            }
        }

        if ($this->connection->fetchAllAssociative('SHOW EVENTS') !== []) {
            throw new RuntimeException('backup_event_unsupported');
        }

        $native = $this->connection->getNativeConnection();

        if (!$native instanceof PDO) {
            throw new RuntimeException('backup_driver_unsupported');
        }

        $buffered = $native->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
        $lockWait = (int) $this->connection->fetchOne('SELECT @@SESSION.lock_wait_timeout');
        $locked = false;
        $bytes = 0;
        $rows = 0;
        $deadline = microtime(true) + 10;
        $manifest = ['format' => 1, 'platform' => $this->connection->getDatabasePlatform()::class, 'tables' => []];

        try {
            $this->connection->executeStatement('SET SESSION lock_wait_timeout = 2');
            $this->connection->executeStatement('LOCK TABLES ' . implode(', ', array_map(
                fn(string $table): string => $this->quote($table) . ' READ',
                $tables,
            )));
            $locked = true;

            foreach ($tables as $table) {
                $guard();
                $quoted = $this->quote($table);
                $columns = $this->connection->fetchAllAssociative('SHOW FULL COLUMNS FROM ' . $quoted);
                $names = [];

                foreach ($columns as $column) {
                    if (preg_match('/(?:VIRTUAL|STORED) GENERATED/i', (string) $column['Extra']) === 1) {
                        continue;
                    }

                    $names[] = (string) $column['Field'];
                }

                if ($names === [] || count($names) > 64) {
                    throw new RuntimeException('backup_column_budget');
                }

                $ddl = $this->connection->fetchNumeric('SHOW CREATE TABLE ' . $quoted);
                $entry = [
                    'name' => $table,
                    'columns' => $names,
                    'ddl' => $ddl[1],
                    'rows' => 0,
                    'bytes' => 0,
                    'chunks' => [],
                ];
                $native->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                $result = $this->connection->executeQuery('SELECT ' . implode(', ', array_map(
                    fn(string $name): string => 'LEFT(CAST(' . $this->quote($name) . ' AS BINARY), 524289)',
                    $names,
                )) . ' FROM ' . $quoted);
                $buffer = '';

                try {
                    while (($row = $result->fetchNumeric()) !== false) {
                        $line = BackupRowCodec::encode($row);
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
                    $native->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
                }

                if ($buffer !== '') {
                    $entry['chunks'][] = self::save($store, $buffer);
                }

                $manifest['tables'][] = $entry;
            }

            if ($tables !== $this->tables() || microtime(true) > $deadline) {
                throw new RuntimeException('backup_snapshot_changed');
            }

            $manifest['files'] = $captureFiles === null ? null : $captureFiles();

            if (microtime(true) > $deadline) {
                throw new RuntimeException('backup_snapshot_budget');
            }

            $guard();
            $manifest['rows'] = $rows;
            $manifest['bytes'] = $bytes;

            return self::save($store, json_encode($manifest, JSON_THROW_ON_ERROR));
        } finally {
            if ($locked) {
                $native->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
                $this->connection->executeStatement('UNLOCK TABLES');
            }

            $this->connection->executeStatement('SET SESSION lock_wait_timeout = ' . $lockWait);
        }
    }

    /**
     * Restore only a trusted local export into an empty, explicitly selected database; never a live-site rollback.
     *
     * @param array{id: string, digest: string} $handle
     */
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

        $checks = (int) $this->connection->fetchOne('SELECT @@FOREIGN_KEY_CHECKS');
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($manifest['tables'] as $entry) {
                $this->connection->executeStatement($entry['ddl']);
                $sql = 'INSERT INTO ' . $this->quote($entry['name']) . ' (' . implode(', ', array_map(
                    fn(string $name): string => $this->quote($name),
                    $entry['columns'],
                )) . ') VALUES (' . implode(', ', array_fill(0, count($entry['columns']), '?')) . ')';

                foreach ($entry['chunks'] as $chunk) {
                    foreach (self::lines($store->read($chunk['id'], $chunk['digest'])) as $line) {
                        $this->connection->executeStatement(
                            $sql,
                            BackupRowCodec::decode($line, count($entry['columns'])),
                        );
                    }
                }
            }
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = ' . $checks);
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $rows = $this->connection->fetchAllNumeric('SHOW FULL TABLES');
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
