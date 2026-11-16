<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use Closure;
use Nolviko\Core\View\AssetManifest;
use RuntimeException;

/**
 * Trusted server-defined fresh-runtime probes; no admin URL, arbitrary PHP or public maintenance bypass.
 */
final class UpdateSmokeCheck
{
    /**
     * @param array<string, Closure(): bool> $probes Must target the new runtime in an isolated request/process.
     */
    public function __construct(private readonly string $liveRoot, private readonly array $probes)
    {
        foreach (['runtime', 'routing', 'database'] as $name) {
            if (!(($probes[$name] ?? null) instanceof Closure)) {
                throw new RuntimeException('update_smoke_probe_missing');
            }
        }
    }

    public function integrity(FileSwitchPlan $plan): void
    {
        if (!file_exists($this->liveRoot . '/config/maintenance.lock')) {
            throw new RuntimeException('update_smoke_maintenance_required');
        }

        foreach (['config/local.php', 'config/install.lock'] as $required) {
            if (is_link($this->liveRoot . '/' . $required) || !is_file($this->liveRoot . '/' . $required)) {
                throw new RuntimeException('update_smoke_installation_missing');
            }
        }

        foreach ($plan->operations as $operation) {
            FileSwitchPlan::regularParents($this->liveRoot, $operation['path']);
            $path = $this->liveRoot . '/' . $operation['path'];

            if ($operation['hash'] === null) {
                if (file_exists($path)) {
                    throw new RuntimeException('update_smoke_obsolete_file');
                }
            } elseif (!is_file($path) || hash_file('sha256', $path) !== $operation['hash']) {
                throw new RuntimeException('update_smoke_integrity_failed');
            }
        }
    }

    public function run(FileSwitchPlan $plan): void
    {
        $this->integrity($plan);
        $path = $this->liveRoot . '/config/assets.json';

        if (!is_file($path) || is_link($path) || filesize($path) > 1048576) {
            throw new RuntimeException('update_smoke_assets_missing');
        }

        $map = json_decode((string) file_get_contents($path), true, 4, JSON_THROW_ON_ERROR);

        if (!is_array($map) || count($map) > 10000) {
            throw new RuntimeException('update_smoke_assets_invalid');
        }

        $assets = new AssetManifest($this->liveRoot);

        foreach ($map as $name => $file) {
            if (!is_string($name) || !is_string($file)) {
                throw new RuntimeException('update_smoke_assets_invalid');
            }

            $assets->path($name);
        }

        foreach (['runtime', 'routing', 'database'] as $name) {
            if (($this->probes[$name])() !== true) {
                throw new RuntimeException('update_smoke_probe_failed:' . $name);
            }
        }

        $this->integrity($plan);
    }
}
