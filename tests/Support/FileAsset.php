<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

use Nolviko\Modules\Importers\Domain\ImportAsset;

/** A plain file as an import asset (the extension's LocalImportAsset adds the source-folder checks). */
final readonly class FileAsset implements ImportAsset
{
    public function __construct(private string $path)
    {
    }

    public function size(): int
    {
        return (int) filesize($this->path);
    }

    public function digest(): string
    {
        return (string) hash_file('sha256', $this->path);
    }

    public function copy(mixed $destination): void
    {
        $source = fopen($this->path, 'rb');
        stream_copy_to_stream($source, $destination);
        fclose($source);
    }
}
