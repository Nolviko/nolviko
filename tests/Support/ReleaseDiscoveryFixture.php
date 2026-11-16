<?php

declare(strict_types=1);

namespace Nolviko\Tests\Support;

/**
 * Public release-list data with source URLs deliberately separate from installable uploaded assets.
 */
final class ReleaseDiscoveryFixture
{
    public const NOW = 1793620800;

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    public static function release(int $id = 1, array $changes = []): array
    {
        return array_replace([
            'id' => $id,
            'tag_name' => 'v1.0.' . $id,
            'draft' => false,
            'prerelease' => false,
            'published_at' => '2026-11-02T12:00:00Z',
            'zipball_url' => 'https://untrusted.invalid/source.zip',
            'tarball_url' => 'https://untrusted.invalid/source.tar.gz',
            'assets' => [[
                'id' => $id * 10,
                'name' => 'Nolviko-' . $id . '.zip',
                'state' => 'uploaded',
                'size' => 1024,
                'browser_download_url' => 'https://untrusted.invalid/download.zip',
            ]],
        ], $changes);
    }

    /**
     * @param list<array<string, mixed>> $releases
     */
    public static function body(array $releases = []): string
    {
        return json_encode($releases, JSON_THROW_ON_ERROR);
    }
}
