<?php

declare(strict_types=1);

/*
 * CLI bootstrap smoke check (`composer smoke`). With --no-dev it also proves that no development
 * package is installed. Standalone on purpose: it runs in --no-dev installs without tools/src.
 */

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/config/bootstrap.php';

$noDev = ($argv[1] ?? '') === '--no-dev';
$developmentToolsPresent = class_exists(TestCase::class)
    || InstalledVersions::isInstalled('squizlabs/php_codesniffer')
    || InstalledVersions::isInstalled('phpunit/phpunit');

if ($noDev && $developmentToolsPresent) {
    throw new RuntimeException('development_package_in_production_vendor');
}

echo json_encode(
    [
        'php' => PHP_VERSION,
        'bootstrap' => 'ready',
        'http_method_overrides' => Request::getAllowedHttpMethodOverride(),
        'development_tools_excluded' => $noDev,
    ],
    JSON_THROW_ON_ERROR,
) . PHP_EOL;
