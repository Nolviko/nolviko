<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Table;
use Nolviko\Core\Extensions\ExtensionCatalog;
use Nolviko\Core\Extensions\ExtensionFailure;
use Nolviko\Core\Extensions\ExtensionManifest;
use Throwable;

/** Governed expand-only table migrations; durable checkpoints survive MySQL implicit DDL commits. */
final readonly class ExtensionMigrations
{
    public function __construct(
        private Connection $connection,
        private ExtensionCatalog $catalog,
    ) {
    }

    public function apply(ExtensionManifest $manifest): void
    {
        if ($this->connection->isTransactionActive()) {
            throw new ExtensionFailure('extension_migration_transaction_active');
        }

        $previous = '';
        foreach ($manifest->data['migrations'] ?? [] as $id) {
            if (strcmp($id, $previous) <= 0) {
                throw new ExtensionFailure('extension_migration_order_invalid');
            }

            $previous = $id;
            $file = $this->catalog->file($manifest, 'migrations/' . $id . '.json');
            if (filesize($file) > 65536) {
                throw new ExtensionFailure('extension_migration_invalid');
            }

            $body = (string) file_get_contents($file);
            $checksum = hash('sha256', $body);
            $entry = $this->connection->fetchAssociative(
                'SELECT checksum, status FROM extension_schema WHERE extension_id = ? AND migration_id = ?',
                [$manifest->id, $id],
            );
            if ($entry !== false && !hash_equals($entry['checksum'], $checksum)) {
                throw new ExtensionFailure('extension_migration_changed');
            }

            if ($entry !== false && $entry['status'] === 'done') {
                continue;
            }

            $tables = $this->tables($manifest, $body);
            if ($entry === false) {
                $this->connection->insert('extension_schema', [
                    'extension_id' => $manifest->id, 'migration_id' => $id,
                    'checksum' => $checksum, 'status' => 'pending',
                ]);
            }

            try {
                $schema = $this->connection->createSchemaManager();
                foreach ($tables as $table) {
                    if ($schema->tablesExist([$table->getName()])) {
                        $actual = $schema->introspectTable($table->getName());
                        foreach ($table->getColumns() as $column) {
                            if (
                                !$actual->hasColumn($column->getName())
                                || !$this->sameColumn($actual->getColumn($column->getName()), $column)
                            ) {
                                throw new ExtensionFailure('extension_migration_schema_conflict');
                            }
                        }

                        continue;
                    }

                    foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
                        $this->connection->executeStatement($sql);
                    }
                }

                $this->connection->update('extension_schema', ['status' => 'done'], [
                    'extension_id' => $manifest->id, 'migration_id' => $id,
                ]);
            } catch (Throwable $failure) {
                $this->connection->update('extension_schema', ['status' => 'failed'], [
                    'extension_id' => $manifest->id, 'migration_id' => $id,
                ]);
                throw new ExtensionFailure('extension_migration_failed', 0, $failure);
            }
        }
    }

    /**
     * "Remove" (2026-11-20, ADR-038): deletes the rows of the own tables the manifest lists under `remove.clear`
     * (stored credentials, for example). The tables stay; nothing outside the extension's prefix is touched.
     */
    public function clear(ExtensionManifest $manifest): void
    {
        $schema = $this->connection->createSchemaManager();
        foreach ($manifest->clearedOnRemove() as $table) {
            if (!str_starts_with($table, $manifest->tablePrefix())) {
                throw new ExtensionFailure('extension_namespace_invalid');
            }
            if ($schema->tablesExist([$table])) {
                $this->connection->executeStatement('DELETE FROM ' . $this->connection->quoteIdentifier($table));
            }
        }
    }

    private function sameColumn(Column $actual, Column $expected): bool
    {
        return $actual->getType()::class === $expected->getType()::class
            && $actual->getNotnull() === $expected->getNotnull()
            && $actual->getLength() === $expected->getLength();
    }

    /** @return list<Table> */
    private function tables(ExtensionManifest $manifest, string $json): array
    {
        $data = json_decode($json, true, 10);
        if (!is_array($data) || array_keys($data) !== ['tables'] || !is_array($data['tables'])) {
            throw new ExtensionFailure('extension_migration_invalid');
        }

        if (count($data['tables']) < 1 || count($data['tables']) > 8) {
            throw new ExtensionFailure('extension_migration_invalid');
        }

        $tables = [];
        foreach ($data['tables'] as $local => $columns) {
            $name = is_string($local) ? $manifest->table($local) : '';
            if (
                $name === '' || strlen($name) > 63
                || preg_match('/^[a-z][a-z0-9_]+$/D', $name) !== 1 || !is_array($columns)
                || count($columns) < 1 || count($columns) > 32
            ) {
                throw new ExtensionFailure('extension_namespace_invalid');
            }

            $table = new Table($name);
            foreach ($columns as $column => $type) {
                if (
                    !is_string($column) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $column) !== 1
                    || !in_array($type, ['bigint', 'integer', 'string', 'text', 'boolean'], true)
                ) {
                    throw new ExtensionFailure('extension_migration_invalid');
                }

                $options = $type === 'string' ? ['length' => 255] : [];
                $table->addColumn($column, $type, $options);
            }

            $tables[] = $table;
        }

        return $tables;
    }
}
