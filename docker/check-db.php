<?php

declare(strict_types=1);

/*
 * Container database smoke check for the configured DB_DRIVER (runtime-policy names: mysql, mariadb,
 * postgresql). Exit 0 with a JSON line on success, exit 1 with a credential-free message otherwise.
 * Standalone on purpose: it is copied to /opt/nolviko without the application autoloader.
 */

$mysqlFamilyQuery = 'SELECT VERSION() AS version, @@character_set_connection AS charset, '
    . '@@session.time_zone AS timezone';

// Reference families from docs/DATABASE_PORTABILITY.md, with the server settings each must report.
$engines = [
    'mysql' => [
        'dsn' => 'mysql:host=%s;dbname=%s;charset=utf8mb4',
        'query' => $mysqlFamilyQuery,
        'accepts' => static fn(array $server): bool => str_starts_with($server['version'], '8.4.')
            && stripos($server['version'], 'mariadb') === false
            && $server['charset'] === 'utf8mb4'
            && $server['timezone'] === '+00:00',
    ],
    'mariadb' => [
        'dsn' => 'mysql:host=%s;dbname=%s;charset=utf8mb4',
        'query' => $mysqlFamilyQuery,
        'accepts' => static fn(array $server): bool => str_starts_with($server['version'], '11.4.')
            && stripos($server['version'], 'mariadb') !== false
            && $server['charset'] === 'utf8mb4'
            && $server['timezone'] === '+00:00',
    ],
    'postgresql' => [
        'dsn' => 'pgsql:host=%s;dbname=%s',
        'query' => "SELECT current_setting('server_version') AS version, "
            . "current_setting('client_encoding') AS charset, current_setting('TimeZone') AS timezone",
        'accepts' => static fn(array $server): bool => preg_match('/^16(\.|$)/', $server['version']) === 1
            && $server['charset'] === 'UTF8'
            && in_array($server['timezone'], ['UTC', 'Etc/UTC'], true),
    ],
];

$driver = getenv('DB_DRIVER') ?: 'mysql';

try {
    if (!isset($engines[$driver])) {
        throw new RuntimeException('Unsupported database driver');
    }

    $engine = $engines[$driver];
    $connection = new PDO(
        sprintf($engine['dsn'], getenv('DB_HOST'), getenv('DB_DATABASE')),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $server = $connection->query($engine['query'])->fetch(PDO::FETCH_ASSOC);

    if (!is_array($server)) {
        throw new RuntimeException('Database returned no server settings');
    }

    $server['version'] = (string) $server['version'];

    if (!$engine['accepts']($server)) {
        throw new RuntimeException('Unexpected database configuration');
    }

    echo json_encode(['driver' => $driver, ...$server], JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Database smoke check failed; credentials are not logged.\n");
    exit(1);
}
