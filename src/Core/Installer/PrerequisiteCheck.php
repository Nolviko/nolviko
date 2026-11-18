<?php

declare(strict_types=1);

namespace Nolviko\Core\Installer;

use Closure;
use Symfony\Component\HttpFoundation\Request;

/**
 * The installer's first screen: runtime policy (config/runtime-policy.php, evaluated in the web SAPI that
 * serves this request), storage writability and the HTTP exposure of private directories.
 *
 * A failed runtime item normally never reaches this screen (the front controller answers 503 first); it is
 * still listed here so the screen is complete when shown. Exposure is a warning, not a blocker: some hosts
 * only allow fixing it after installation, and the self-check repeats it (M01.15).
 */
final class PrerequisiteCheck
{
    /** @var array<string, string> Exposure result => message ID. */
    private const EXPOSURE_MESSAGES = [
        WebExposureProbe::PROTECTED => 'installer.check.web_protected',
        WebExposureProbe::EXPOSED => 'installer.check.web_exposed',
        WebExposureProbe::UNKNOWN => 'installer.check.web_unknown',
    ];

    private readonly Closure $clock;

    /**
     * @param (Closure(): string)|null $clock Today as `Y-m-d` (UTC); null means the current date.
     * @param array{version?: string, extensions?: list<string>, memory_limit?: string}|null $runtime Overrides
     *     for tests; null means this PHP process.
     */
    public function __construct(
        private readonly string $root,
        private readonly string $storage,
        private readonly WebExposureProbe $probe,
        private readonly ?string $databaseDriver = null,
        ?Closure $clock = null,
        private readonly ?array $runtime = null,
    ) {
        $this->clock = $clock ?? static fn(): string => gmdate('Y-m-d');
    }

    public function run(Request $request): PrerequisiteReport
    {
        // Bootstrap normally loaded it already (possibly from another path in tests): never declare twice.
        if (!function_exists('forumRuntimeErrors')) {
            require_once $this->root . '/config/runtime-policy.php';
        }

        return new PrerequisiteReport($this->runtimeResults(), [
            $this->storageResult(),
            ...$this->exposureResults($request),
        ]);
    }

    /**
     * @return list<PrerequisiteResult>
     */
    private function runtimeResults(): array
    {
        $version = $this->runtime['version'] ?? PHP_VERSION;
        $memory = $this->runtime['memory_limit'] ?? (string) ini_get('memory_limit');
        $extensions = $this->runtime['extensions'] ?? get_loaded_extensions();
        $results = [
            \forumRuntimeVersionErrors($version, ($this->clock)()) === []
                ? new PrerequisiteResult(PrerequisiteResult::PASSED, 'installer.check.php_version', [
                    'version' => $version,
                ])
                : new PrerequisiteResult(PrerequisiteResult::FAILED, 'diagnostics.php_version_unsupported', [
                    'version' => $version,
                ]),
        ];
        // Beta.10: a branch past its upstream security support still installs and runs; the owner is told.
        if (\forumRuntimeVersionWarnings($version, ($this->clock)()) !== []) {
            $results[] = new PrerequisiteResult(PrerequisiteResult::WARNING, 'installer.check.php_end_of_life', [
                'version' => $version,
            ]);
        }
        // Before installation no database type is chosen: a host with only pdo_pgsql passes with PostgreSQL.
        $chosen = $this->databaseDriver ?? (!in_array('pdo_mysql', $extensions, true)
            && in_array('pdo_pgsql', $extensions, true) ? 'postgresql' : 'mysql');
        $driver = \forumRuntimeDriverExtension($chosen);
        $missing = \forumRuntimeExtensionErrors($extensions, $driver);

        // Every required extension is "missing" from an empty list: that is the list to show.
        foreach (\forumRuntimeExtensionErrors([], $driver) as $required) {
            $extension = substr($required, strlen('extension_missing:'));
            $results[] = in_array($required, $missing, true)
                // Beta.10: say what the missing extension is for, so the owner can ask the host precisely.
                ? new PrerequisiteResult(PrerequisiteResult::FAILED, 'installer.check.extension_missing', [
                    'extension' => $extension,
                    'feature' => self::feature($extension),
                ])
                : new PrerequisiteResult(PrerequisiteResult::PASSED, 'installer.check.extension', [
                    'extension' => $extension,
                ]);
        }

        $memoryErrors = \forumRuntimeMemoryErrors($memory);
        $results[] = $memoryErrors === []
            ? new PrerequisiteResult(PrerequisiteResult::PASSED, 'installer.check.memory_limit', [
                'limit' => $memory,
            ])
            : new PrerequisiteResult(PrerequisiteResult::FAILED, 'diagnostics.' . $memoryErrors[0]);

        return $results;
    }

    /** What a required extension is used for (ICU select key in installer.check.extension_missing). */
    private static function feature(string $extension): string
    {
        return match (strtolower($extension)) {
            'pdo', 'pdo_mysql', 'pdo_pgsql' => 'database',
            'intl' => 'languages',
            'mbstring', 'json' => 'text',
            'zip' => 'packages',
            'openssl', 'sodium' => 'security',
            'fileinfo', 'gd' => 'uploads',
            'session' => 'sign_in',
            default => 'other',
        };
    }

    private function storageResult(): PrerequisiteResult
    {
        $probe = $this->storage . '/.write-test-' . bin2hex(random_bytes(6));
        $writable = is_dir($this->storage) && @file_put_contents($probe, 'x') === 1;
        @unlink($probe);

        return $writable
            ? new PrerequisiteResult(PrerequisiteResult::PASSED, 'installer.check.storage_writable')
            : new PrerequisiteResult(PrerequisiteResult::FAILED, 'diagnostics.storage_not_writable');
    }

    /**
     * @return list<PrerequisiteResult>
     */
    private function exposureResults(Request $request): array
    {
        $results = [];

        foreach ($this->probe->probe($request, $this->root, $this->storage) as $directory => $exposure) {
            $status = $exposure === WebExposureProbe::PROTECTED
                ? PrerequisiteResult::PASSED
                : PrerequisiteResult::WARNING;
            $results[] = new PrerequisiteResult($status, self::EXPOSURE_MESSAGES[$exposure], [
                'directory' => $directory . '/',
            ]);
        }

        return $results;
    }
}
