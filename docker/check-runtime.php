<?php

declare(strict_types=1);

/*
 * Container prerequisite probe run at image build and container start (see docs/HOSTING.md).
 * Prints a JSON report without credentials or phpinfo output; exit 1 when any requirement fails.
 */

require __DIR__ . '/runtime-policy.php';

$storageDirectory = '/var/www/storage';
$driver = getenv('DB_DRIVER') ?: 'mysql';
$errors = forumRuntimeErrors(
    PHP_VERSION,
    get_loaded_extensions(),
    (string) ini_get('memory_limit'),
    gmdate('Y-m-d'),
    $driver,
);

if (!is_dir($storageDirectory) || !is_writable($storageDirectory)) {
    $errors[] = 'storage_not_writable';
}

echo json_encode(
    [
        'php' => PHP_VERSION,
        'database_driver' => $driver,
        'memory_limit' => ini_get('memory_limit'),
        'sapi' => PHP_SAPI,
        'extensions' => get_loaded_extensions(),
        'opcache_cli' => ini_get('opcache.enable_cli'),
        'errors' => $errors,
    ],
    JSON_THROW_ON_ERROR,
) . PHP_EOL;

exit($errors === [] ? 0 : 1);
