<?php

declare(strict_types=1);

namespace Nolviko\Core\Application;

use Nolviko\Core\Config\ConfigException;

/**
 * Where an installation stands, from config/local.php and the install lock (config/install.lock).
 *
 * The lock lives in config/, not storage/, so clearing caches can never reopen the installer.
 */
enum InstallationState
{
    /** No config yet: only the installer is reachable. */
    case NotConfigured;

    /** Config written, install not finished (M01.14 migrations / first admin): still the installer. */
    case Configured;

    /** Config and lock: the forum runs and installer routes are 404. */
    case Installed;

    public const CONFIG_FILE = 'local.php';

    public const LOCK_FILE = 'install.lock';

    /**
     * @throws ConfigException `config_missing` when the lock exists without config: never reopen the installer.
     */
    public static function detect(string $configDirectory): self
    {
        $configured = is_file($configDirectory . '/' . self::CONFIG_FILE);
        $locked = is_file($configDirectory . '/' . self::LOCK_FILE);

        return match (true) {
            $configured && $locked => self::Installed,
            $configured => self::Configured,
            $locked => throw new ConfigException(['config_missing']),
            default => self::NotConfigured,
        };
    }

    public function isInstalled(): bool
    {
        return $this === self::Installed;
    }
}
