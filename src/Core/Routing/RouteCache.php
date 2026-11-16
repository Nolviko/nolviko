<?php

declare(strict_types=1);

namespace Nolviko\Core\Routing;

use Throwable;

/**
 * Compiles routes once per release into `<directory>/routes-<release>.php` and loads that file afterwards.
 *
 * Never fails a request: an unreadable or corrupt file is recompiled, and an unwritable directory (or no
 * directory at all) means compiling in memory for this request. Files of other releases are deleted.
 */
final class RouteCache
{
    private const FILE_PREFIX = 'routes-';

    private const DIRECTORY_MODE = 0775;

    public function __construct(
        private readonly ?string $directory,
    ) {
    }

    /**
     * @param list<RouteDefinition> $definitions
     */
    public function load(string $releaseId, array $definitions): CompiledRoutes
    {
        $file = $this->file($releaseId);
        $cached = $file === null ? null : self::read($file, $releaseId);

        if ($cached !== null) {
            return $cached;
        }

        $compiled = CompiledRoutes::compile($definitions);

        if ($file !== null) {
            $this->write($file, $compiled->export($releaseId));
        }

        return $compiled;
    }

    public function file(string $releaseId): ?string
    {
        if ($this->directory === null || preg_match(ReleaseId::PATTERN, $releaseId) !== 1) {
            return null;
        }

        return $this->directory . '/' . self::FILE_PREFIX . $releaseId . '.php';
    }

    private static function read(string $file, string $releaseId): ?CompiledRoutes
    {
        if (!is_file($file)) {
            return null;
        }

        try {
            $data = (static fn(string $path): mixed => require $path)($file);
        } catch (Throwable) {
            return null;
        }

        return CompiledRoutes::fromExport($data, $releaseId);
    }

    /**
     * Atomic replace (temporary file plus rename), then removal of other releases' files.
     */
    private function write(string $file, string $source): void
    {
        $directory = (string) $this->directory;

        if (!is_dir($directory) && !@mkdir($directory, self::DIRECTORY_MODE, true) && !is_dir($directory)) {
            return;
        }

        $temporary = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temporary, $source, LOCK_EX) === false || !@rename($temporary, $file)) {
            @unlink($temporary);

            return;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        foreach (glob($directory . '/' . self::FILE_PREFIX . '*.php') ?: [] as $other) {
            if ($other !== $file) {
                @unlink($other);
            }
        }
    }
}
