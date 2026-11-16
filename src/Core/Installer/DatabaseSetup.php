<?php

declare(strict_types=1);

namespace Nolviko\Core\Installer;

use Closure;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\ConfigException;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Config\ConfigSchema;
use Nolviko\Core\Config\ConfigWriter;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\DatabaseHealthCheck;
use Nolviko\Core\Database\DatabaseHealthException;
use RuntimeException;

/**
 * Installer step 2: validate the entered database settings, test them (M01.08 health check) and only then
 * write `config/local.php` with a new application secret. Diagnostics are IDs; the password is never part
 * of one and is never sent back to the form.
 */
final class DatabaseSetup
{
    /** Tried first; a MariaDB server is detected and written as `mariadb` (10.11+, 2026-11-02). */
    public const DRIVER = 'mysql';

    /** The installer's type choice (2026-11-14): the MySQL family (detected) or PostgreSQL. */
    public const POSTGRESQL = 'postgresql';

    private const MAX_LENGTH = ['host' => 255, 'name' => 64, 'user' => 80, 'password' => 4096];

    private const NAME_PATTERN = '/^[A-Za-z0-9_$-]+$/D';

    private readonly Closure $verify;

    /**
     * @param string $configDirectory Where `local.php` is written.
     * @param string $publicDirectory The document root; the configuration must stay outside it.
     * @param (Closure(Config): void)|null $verify Throws DatabaseHealthException; null means connecting for real.
     */
    public function __construct(
        private readonly string $configDirectory,
        private readonly string $publicDirectory,
        ?Closure $verify = null,
    ) {
        $this->verify = $verify ?? static function (Config $config): void {
            DatabaseHealthCheck::check(ConnectionFactory::create($config), $config->databaseDriver);
        };
    }

    /**
     * @param array<string, mixed> $input Raw form fields host, port, name, user, password.
     * @param string|null $siteUrl The address the installer is used at (`app.url`, for links in mail, M02.03).
     *
     * @return list<string> Diagnostics; empty when the configuration was written.
     */
    public function configure(array $input, ?string $siteUrl = null): array
    {
        $settings = self::settings($input, $diagnostics);

        if ($siteUrl !== null && ConfigSchema::isBaseUrl($siteUrl)) {
            $settings['app']['url'] = rtrim($siteUrl, '/');
        }

        if ($diagnostics !== []) {
            return $diagnostics;
        }

        try {
            try {
                ($this->verify)(ConfigLoader::fromArray($settings));
            } catch (DatabaseHealthException $failure) {
                // For the MySQL family the person installing only gives the server; its family decides the driver.
                if (
                    $settings['database']['driver'] !== self::DRIVER
                    || $failure->diagnostics() !== ['database_family_invalid:mariadb']
                ) {
                    throw $failure;
                }

                $settings['database']['driver'] = 'mariadb';
                ($this->verify)(ConfigLoader::fromArray($settings));
            }
        } catch (DatabaseHealthException $failure) {
            return $failure->diagnostics();
        } catch (ConfigException $failure) {
            return $failure->diagnostics();
        }

        try {
            ConfigWriter::write($this->configDirectory . '/local.php', $settings, $this->publicDirectory);
        } catch (RuntimeException $failure) {
            return [$failure->getMessage()];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string>|null $diagnostics
     *
     * @return array<string, array<string, string|int>>
     */
    private static function settings(array $input, ?array &$diagnostics): array
    {
        $diagnostics = [];
        $field = static fn(string $name): string => is_string($input[$name] ?? null) ? $input[$name] : '';
        $host = trim($field('host'));
        $name = trim($field('name'));
        $user = trim($field('user'));
        $password = $field('password');
        $port = trim($field('port'));

        foreach (['host' => $host, 'name' => $name, 'user' => $user, 'password' => $password] as $key => $value) {
            if (strlen($value) > self::MAX_LENGTH[$key] || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                $diagnostics[] = 'installer_field_invalid:' . $key;
            } elseif ($key !== 'password' && $value === '') {
                $diagnostics[] = 'installer_field_missing:' . $key;
            }
        }

        if ($name !== '' && preg_match(self::NAME_PATTERN, $name) !== 1) {
            $diagnostics[] = 'installer_field_invalid:name';
        }

        if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
            $diagnostics[] = 'installer_field_invalid:port';
        }

        $driver = $field('driver') === self::POSTGRESQL ? self::POSTGRESQL : self::DRIVER;
        $database = ['driver' => $driver, 'host' => $host, 'name' => $name, 'user' => $user];

        if ($port !== '') {
            $database['port'] = (int) $port;
        }

        return [
            'database' => $database + ['password' => $password],
            'app' => ['secret' => ConfigWriter::generateSecret()],
        ];
    }
}
