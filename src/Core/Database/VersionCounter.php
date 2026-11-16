<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\Connection;

/**
 * Integer versions kept in `settings` (value_type `int`) for cache invalidation: a writer bumps the version
 * inside its own transaction, and readers put the current version into their cache keys, so a change makes
 * every cached derivative unreachable at once without deleting files. Portable read-modify-write under a row
 * lock; a missing row is version 0.
 */
final class VersionCounter
{
    public const PERMISSION_RULES = 'permissions.rules_version';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function current(string $key): int
    {
        $value = $this->connection->fetchOne('SELECT setting_value FROM settings WHERE setting_key = ?', [$key]);

        return $value === false ? 0 : (int) $value;
    }

    /**
     * Call inside the writer's transaction.
     */
    public static function bump(Connection $connection, string $key): void
    {
        $current = $connection->fetchOne(
            'SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE',
            [$key],
        );
        $now = gmdate('Y-m-d H:i:s');

        if ($current === false) {
            $connection->insert('settings', [
                'setting_key' => $key,
                'setting_value' => '1',
                'value_type' => 'int',
                'updated_at' => $now,
            ]);

            return;
        }

        $connection->update(
            'settings',
            ['setting_value' => (string) ((int) $current + 1), 'updated_at' => $now],
            ['setting_key' => $key],
        );
    }
}
