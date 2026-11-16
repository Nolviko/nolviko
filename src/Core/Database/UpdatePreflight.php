<?php

declare(strict_types=1);

namespace Nolviko\Core\Database;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\Migration\MigrationRegistry;
use Nolviko\Core\Extensions\ExtensionBootstrap;
use Nolviko\Core\Updates\InstalledInventory;
use Nolviko\Core\Updates\ReleaseCompatibility;
use Nolviko\Core\Updates\ReleaseEligibility;
use Nolviko\Core\Updates\ReleaseVersion;
use Nolviko\Core\Updates\SignedReleaseManifest;
use Nolviko\Core\Updates\SiteAccessLock;
use Nolviko\Core\Updates\UpdateTrust;
use Nolviko\Core\View\ThemeCatalog;
use RuntimeException;

/** Only server-observed facts feed the pure eligibility policy; request fields cannot assert safety gates. */
final readonly class UpdatePreflight
{
    public function __construct(
        private Connection $db,
        private string $site,
        private string $storage,
        private string $driver,
    ) {
    }

    public function current(UpdateTrust $trust): ReleaseVersion
    {
        $version = $this->db->fetchOne('SELECT release_version FROM installed_release WHERE id = 1');
        return is_string($version) ? new ReleaseVersion($version) : $trust->installed;
    }

    public function issues(
        ReleaseCompatibility $target,
        SignedReleaseManifest $manifest,
        bool $confirmed,
        bool $captureLockHeld = false,
    ): array {
        $trust = UpdateTrust::load($this->site) ?? throw new RuntimeException('update_trust_missing');
        $files = InstalledInventory::load($this->site);
        $intact = InstalledInventory::intact($this->site, $files);
        $writable = is_writable($this->site) && is_writable($this->site . '/config')
            && is_writable($this->storage);
        foreach (array_keys($files) as $path) {
            $writable = $writable && is_writable(dirname($this->site . '/' . $path));
        }
        $lock = $captureLockHeld ? true : (new SiteAccessLock($this->storage))->acquire(false);
        SiteAccessLock::release($lock);
        $private = realpath($this->storage);
        $application = realpath($this->site);
        $public = realpath($this->site . '/public');
        $privateStorage = $private !== false && $public !== false && $application !== false
            && !is_link($this->storage)
            && !str_starts_with(
                strtolower(str_replace('\\', '/', $private)) . '/',
                strtolower(str_replace('\\', '/', $application)) . '/',
            )
            && !str_starts_with(
                strtolower(str_replace('\\', '/', $private)) . '/',
                strtolower(str_replace('\\', '/', $public)) . '/'
            );
        if ($this->driver === 'postgresql') {
            // `16.16 (Debian ...)`: major.minor, read as major.minor.0 for the release constraints.
            $rawVersion = (string) $this->db->fetchOne("SELECT current_setting('server_version')");
            $match = preg_match('/^([0-9]+)\.([0-9]+)/', $rawVersion, $parts) === 1
                ? [1 => $parts[1] . '.' . $parts[2] . '.0'] : [];
        } else {
            $rawVersion = (string) $this->db->fetchOne('SELECT VERSION()');
            preg_match(str_contains($rawVersion, 'MariaDB') ? '/([0-9]+\.[0-9]+\.[0-9]+)-MariaDB/'
                : '/([0-9]+\.[0-9]+\.[0-9]+)/', $rawVersion, $match);
        }
        $schema = is_file($this->site . '/config/install.lock')
            && is_file($this->site . '/config/local.php');
        $migrations = MigrationRegistry::core()->migrations;
        if (count($migrations) > 512) {
            throw new RuntimeException('update_schema_budget');
        }
        $history = $this->db->fetchAllAssociative(
            'SELECT id, status, checksum FROM migration_history WHERE id IN (?)',
            [array_map(static fn($migration): string => $migration->id(), $migrations)],
            [ArrayParameterType::STRING]
        );
        $history = array_column($history, null, 'id');
        foreach ($migrations as $migration) {
            $row = $history[$migration->id()] ?? null;
            if (
                $row === null || $row['status'] !== 'applied'
                || $row['checksum'] !== MigrationRegistry::checksum($migration, $this->driver)
            ) {
                $schema = false;
                break;
            }
        }
        $running = $this->db->fetchOne("SELECT id FROM outbox_events WHERE status = 'running' "
            . 'AND lease_expires_at > CURRENT_TIMESTAMP(6) LIMIT 1');
        $import = $this->db->fetchOne("SELECT id FROM import_jobs WHERE state <> 'completed' LIMIT 1");
        $addons = [];
        foreach (ExtensionBootstrap::manager($this->site, $this->storage)->active() as $extension) {
            $addons[] = ['engine' => $extension->data['engine'], 'php' => $extension->data['php'],
                'packages' => $extension->data['packages'] ?? []];
        }
        $themes = new ThemeCatalog($this->site . '/themes');
        foreach ($themes->all() as $theme) {
            if (!$theme['valid']) {
                throw new RuntimeException('update_theme_incompatible');
            }
            $data = $themes->manifest($theme['id'])->data;
            if (($data['engine'] ?? null) !== $target->data['theme_api']) {
                throw new RuntimeException('update_theme_incompatible');
            }
            $addons[] = ['engine' => '*', 'php' => '>=' . $data['minimum_php']];
        }
        $required = max($manifest->unpackedBytes * 3 + $manifest->archiveBytes, 67108864);
        $free = disk_free_space($this->storage);
        return (new ReleaseEligibility())->assess($target, $this->current($trust), $trust->channel, [
            'php' => PHP_VERSION, 'driver' => $this->driver, 'database_version' => $match[1] ?? '',
            'extensions' => array_map(strtolower(...), get_loaded_extensions()), 'integrity' => $intact,
            'writable' => $writable, 'private_storage' => $privateStorage && $lock !== false,
            'quiescent' => $running === false && $import === false, 'schema' => $schema,
            'backup' => in_array($this->driver, ['mysql', 'mariadb', 'postgresql'], true),
            'free_bytes' => $free === false ? 0 : (int) $free, 'required_bytes' => $required,
        ], $addons, $confirmed);
    }
}
