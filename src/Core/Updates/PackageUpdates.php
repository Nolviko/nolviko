<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Finds newer releases of installed extensions and themes (2026-11-17, owner request: see available updates in the
 * panel, install only with the administrator's approval). A package names its GitHub repository in its manifest
 * (`"repository": "owner/name"`). Discovery is the core's cached, rate-friendly GitHubReleaseDiscovery, so opening an
 * admin page does not call GitHub every time. Best effort: any failure means "no update shown".
 */
final class PackageUpdates
{
    public const REPOSITORY_PATTERN = '~^[A-Za-z0-9][A-Za-z0-9-]{0,38}/[A-Za-z0-9_.-]{1,100}$~D';

    public function __construct(private readonly GitHubReleaseDiscovery $discovery)
    {
    }

    /** The asset prefix of an official extension package, e.g. `nolviko-ext-nolviko-totp-`. */
    public static function extensionAsset(string $id): string
    {
        return 'nolviko-ext-' . str_replace('/', '-', $id) . '-';
    }

    /** The asset prefix of a theme package, e.g. `nolviko-theme-lumen-`. */
    public static function themeAsset(string $id): string
    {
        return 'nolviko-theme-' . $id . '-';
    }

    public static function repository(string $spec): ?ReleaseRepository
    {
        if (preg_match(self::REPOSITORY_PATTERN, $spec) !== 1) {
            return null;
        }
        [$owner, $name] = explode('/', $spec, 2);
        try {
            return new ReleaseRepository($owner, $name);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The newest stable release above $installed that carries `<prefix><version>.zip`.
     *
     * @return array{repository: string, version: string, tag: string, asset: string, bytes: int}|null
     */
    public function available(string $spec, string $installed, string $prefix): ?array
    {
        $repository = self::repository($spec);
        if ($repository === null) {
            return null;
        }
        try {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $result = $this->discovery->check($repository, 'stable', $now);
        } catch (Throwable) {
            return null;
        }

        $best = null;
        foreach ($result->releases as $release) {
            if ($release->prerelease || preg_match('/^v?([0-9]+\.[0-9]+\.[0-9]+)$/D', $release->tag, $match) !== 1) {
                continue;
            }
            $version = $match[1];
            $newer = version_compare($version, $installed, '>')
                && ($best === null || version_compare($version, $best['version'], '>'));
            if (!$newer) {
                continue;
            }
            foreach ($release->assets as $asset) {
                if ($asset['name'] === $prefix . $version . '.zip' && $asset['bytes'] > 0) {
                    $best = ['repository' => $spec, 'version' => $version, 'tag' => $release->tag,
                        'asset' => $asset['name'], 'bytes' => $asset['bytes']];
                }
            }
        }

        return $best;
    }
}
