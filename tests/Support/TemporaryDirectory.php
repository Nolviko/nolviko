<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use FilesystemIterator;
use Nolviko\Tools\Filesystem;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Self-cleaning scratch directory for tests that need real files.
 */
final class TemporaryDirectory
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir() . '/nolviko-test-' . bin2hex(random_bytes(6));
        Filesystem::ensureDirectory($this->path);
    }

    public function write(string $relativePath, string $content): string
    {
        $file = $this->path . '/' . $relativePath;
        Filesystem::ensureDirectory(dirname($file));
        file_put_contents($file, $content);

        return $file;
    }

    /**
     * @param array<string, string> $messages
     */
    public function writeCatalog(string $locale, string $domain, array $messages): string
    {
        return $this->write($locale . '/' . $domain . '.php', '<?php return ' . var_export($messages, true) . ';');
    }

    public function remove(): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }

        rmdir($this->path);
    }
}
