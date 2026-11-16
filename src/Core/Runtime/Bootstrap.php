<?php

declare(strict_types=1);

namespace Nolviko\Core\Runtime;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Process initialization only: no controller, container or database connection.
 */
final class Bootstrap
{
    private function __construct(
        public readonly string $projectDirectory,
    ) {
    }

    /**
     * Validate the project root and runtime prerequisites, then apply safe HTTP request defaults.
     *
     * @throws InvalidArgumentException When the directory is not an application root.
     * @throws RuntimeException When the runtime prerequisite contract is not met.
     */
    public static function boot(string $projectDirectory, string $databaseDriver = 'mysql'): self
    {
        $root = self::resolveProjectRoot($projectDirectory);

        self::assertRuntimeRequirements($root, $databaseDriver);
        self::applyRequestDefaults();

        return new self($root);
    }

    private static function resolveProjectRoot(string $projectDirectory): string
    {
        $root = realpath($projectDirectory);

        if (
            $root === false
            || !is_file($root . '/composer.json')
            || !is_file($root . '/config/runtime-policy.php')
        ) {
            throw new InvalidArgumentException('bootstrap_project_directory_invalid');
        }

        return $root;
    }

    private static function assertRuntimeRequirements(string $root, string $databaseDriver): void
    {
        require_once $root . '/config/runtime-policy.php';

        $errors = \forumRuntimeErrors(
            PHP_VERSION,
            get_loaded_extensions(),
            (string) ini_get('memory_limit'),
            gmdate('Y-m-d'),
            $databaseDriver,
        );

        if ($errors !== []) {
            throw new RuntimeRequirementsException($errors);
        }
    }

    /**
     * Headers must never change the HTTP method or the client address (ADR-022).
     */
    private static function applyRequestDefaults(): void
    {
        Request::setAllowedHttpMethodOverride([]);
        Request::setTrustedProxies([], 0);
    }
}
