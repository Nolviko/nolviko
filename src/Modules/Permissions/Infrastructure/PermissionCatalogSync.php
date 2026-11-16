<?php

declare(strict_types=1);

namespace Nolviko\Modules\Permissions\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionDefinition;

/**
 * Brings `permissions` in line with the code catalog, idempotently: new keys are inserted, changed definitions
 * updated, keys no longer declared retired (and revived if they come back). A second run writes nothing.
 * Runs in one transaction after every completed migration batch (installer, updater).
 */
final class PermissionCatalogSync
{
    private const COLUMNS = 'permission_key, value_type, options, min_value, max_value, retired_at';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{added: int, changed: int, retired: int}
     */
    public function sync(PermissionCatalog $catalog): array
    {
        return $this->connection->transactional(function (Connection $connection) use ($catalog): array {
            $stored = [];
            $rows = $connection->fetchAllAssociative('SELECT ' . self::COLUMNS . ' FROM permissions FOR UPDATE');

            foreach ($rows as $row) {
                $stored[(string) $row['permission_key']] = $row;
            }

            $counts = ['added' => 0, 'changed' => 0, 'retired' => 0];
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');

            foreach ($catalog->all() as $key => $definition) {
                $values = self::values($definition);
                $row = $stored[$key] ?? null;

                if ($row === null) {
                    $connection->insert('permissions', ['permission_key' => $key] + $values + ['synced_at' => $now]);
                    ++$counts['added'];
                } elseif (!self::matches($row, $values)) {
                    $connection->update('permissions', $values + ['synced_at' => $now], ['permission_key' => $key]);
                    ++$counts['changed'];
                }
            }

            foreach (array_diff_key($stored, $catalog->all()) as $key => $row) {
                if ($row['retired_at'] === null) {
                    $connection->update('permissions', ['retired_at' => $now], ['permission_key' => $key]);
                    ++$counts['retired'];
                }
            }

            return $counts;
        });
    }

    /**
     * @return array{value_type: string, options: string, min_value: int, max_value: int, retired_at: null}
     */
    private static function values(PermissionDefinition $definition): array
    {
        return [
            'value_type' => $definition->type->value,
            'options' => implode(',', $definition->options),
            'min_value' => $definition->min,
            'max_value' => $definition->max,
            'retired_at' => null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array{value_type: string, options: string, min_value: int, max_value: int, retired_at: null} $values
     */
    private static function matches(array $row, array $values): bool
    {
        return (string) $row['value_type'] === $values['value_type']
            && (string) $row['options'] === $values['options']
            && (int) $row['min_value'] === $values['min_value']
            && (int) $row['max_value'] === $values['max_value']
            && $row['retired_at'] === null;
    }
}
