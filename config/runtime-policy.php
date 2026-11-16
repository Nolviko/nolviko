<?php

declare(strict_types=1);

/*
 * Runtime prerequisite contract v2 (see docs/HOSTING.md).
 *
 * Dependency-free on purpose: the Docker image runs this file before Composer's autoloader exists.
 * Legacy four-argument calls of forumRuntimeErrors() retain the MySQL default.
 */

/**
 * Return stable diagnostic IDs for every unmet runtime requirement; an empty array means supported.
 *
 * @param list<string> $extensions Loaded extension names, as returned by get_loaded_extensions().
 *
 * @return list<string>
 */
function forumRuntimeErrors(
    string $version,
    array $extensions,
    string $memoryLimit,
    string $today,
    string $database = 'mysql',
): array {
    $errors = forumRuntimeVersionErrors($version, $today);
    $driver = forumRuntimeDriverExtension($database);

    if ($driver === null) {
        $errors[] = 'database_driver_unsupported';
    }

    return [
        ...$errors,
        ...forumRuntimeExtensionErrors($extensions, $driver),
        ...forumRuntimeMemoryErrors($memoryLimit),
    ];
}

/**
 * Accept only plain upstream 8.4/8.5 patch versions at or above the security floor and inside branch support.
 *
 * @return list<string>
 */
function forumRuntimeVersionErrors(string $version, string $today): array
{
    // Branch => [minimum patch release, last day of upstream security support (UTC)].
    $branches = [
        '8.4' => ['8.4.27', '2028-12-31'],
        '8.5' => ['8.5.12', '2029-12-31'],
    ];

    if (preg_match('/^(8\.[45])\.(0|[1-9][0-9]*)$/D', $version, $match) !== 1) {
        return ['php_version_unsupported'];
    }

    [$minimum, $supportEnds] = $branches[$match[1]];

    if (version_compare($version, $minimum, '<') || $today > $supportEnds) {
        return ['php_version_unsupported'];
    }

    return [];
}

/**
 * Map a configured database family to the PDO driver extension it needs, or null when unsupported.
 */
function forumRuntimeDriverExtension(string $database): ?string
{
    return match ($database) {
        'mysql', 'mariadb' => 'pdo_mysql',
        'postgresql' => 'pdo_pgsql',
        default => null,
    };
}

/**
 * @param list<string> $extensions
 *
 * @return list<string>
 */
function forumRuntimeExtensionErrors(array $extensions, ?string $driver): array
{
    // gd: images (avatars, gallery, post images) are re-encoded; required since 2026-11-14 (ADR-032).
    $required = ['PDO', 'intl', 'mbstring', 'zip', 'json', 'openssl', 'fileinfo', 'session', 'sodium', 'gd'];

    if ($driver !== null) {
        $required[] = $driver;
    }

    $loaded = array_map('strtolower', $extensions);
    $errors = [];

    foreach ($required as $extension) {
        if (!in_array(strtolower($extension), $loaded, true)) {
            $errors[] = 'extension_missing:' . $extension;
        }
    }

    return $errors;
}

/**
 * Accept PHP's integer byte count or K/M/G shorthand, and unlimited (-1).
 *
 * @return list<string>
 */
function forumRuntimeMemoryErrors(string $memoryLimit): array
{
    if ($memoryLimit === '-1') {
        return [];
    }

    if (preg_match('/^([0-9]+)([KMG]?)$/Di', trim($memoryLimit), $memory) !== 1) {
        return ['memory_limit_invalid'];
    }

    $multiplier = match (strtoupper($memory[2])) {
        'K' => 1024,
        'M' => 1048576,
        'G' => 1073741824,
        default => 1,
    };

    if ((float) $memory[1] * $multiplier < 134217728) {
        return ['memory_limit_below_128M'];
    }

    return [];
}
