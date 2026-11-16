<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Importers\Domain\ImportSource;
use RuntimeException;
use Throwable;

/** Owner-installed local read-only copies. Credentials are typed Secrets and never returned by the catalogue. */
final readonly class ImportSources
{
    public function __construct(private string $root, private Secret $appSecret)
    {
    }

    public function catalogue(): array
    {
        $result = [];
        foreach ($this->read() as $entry) {
            $result[] = ['id' => $entry['id'], 'engine' => $entry['engine']];
        }
        return $result;
    }

    public function open(string $id): ImportSource
    {
        try {
            foreach ($this->read() as $entry) {
                if ($entry['id'] !== $id) {
                    continue;
                }
                $config = new Config(
                    $entry['driver'],
                    $entry['host'],
                    $entry['port'],
                    $entry['database'],
                    $entry['username'],
                    new Secret($entry['password']),
                    $this->appSecret
                );
                return new SqlImportSource(
                    ConnectionFactory::create($config),
                    $entry['engine'],
                    $entry['prefix'],
                    $entry['id'],
                    $entry['charset'],
                    $entry['attachment_roots'],
                    $entry['user_map']
                );
            }
        } catch (Throwable) {
            // No raw driver error, connection argument, filesystem location or nested secret-bearing exception.
            throw new RuntimeException('import_source_unavailable');
        }
        throw new RuntimeException('import_source_missing');
    }

    private function read(): array
    {
        $path = $this->root . '/config/import-sources.json';
        if (!file_exists($path)) {
            return [];
        }
        if (
            is_link($path) || !is_file($path) || filesize($path) > 65536
            || (DIRECTORY_SEPARATOR === '/' && (fileperms($path) & 0077) !== 0)
        ) {
            throw new RuntimeException('import_sources_invalid');
        }
        $data = json_decode((string) file_get_contents($path), true, 12);
        if (
            !is_array($data) || ($data['format'] ?? null) !== 1 || !is_array($data['sources'] ?? null)
            || !array_is_list($data['sources']) || count($data['sources']) > 8
        ) {
            throw new RuntimeException('import_sources_invalid');
        }
        $seen = [];
        $result = [];
        foreach ($data['sources'] as $source) {
            $allowed = ['id', 'engine', 'driver', 'host', 'port', 'database', 'username', 'password',
                'prefix', 'charset', 'frozen_copy', 'attachment_roots', 'user_map'];
            if (
                !is_array($source) || array_diff(array_keys($source), $allowed) !== []
                || ($source['frozen_copy'] ?? false) !== true
            ) {
                throw new RuntimeException('import_sources_invalid');
            }
            $source += ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'charset' => 'UTF-8',
                'prefix' => '', 'attachment_roots' => [], 'user_map' => []];
            foreach (
                ['id', 'engine', 'driver', 'host', 'database', 'username', 'password', 'prefix',
                'charset'] as $key
            ) {
                if (!is_string($source[$key] ?? null)) {
                    throw new RuntimeException('import_sources_invalid');
                }
            }
            if (
                preg_match('/^[A-Za-z0-9._-]{8,64}$/D', $source['id']) !== 1 || isset($seen[$source['id']])
                || !in_array($source['engine'], ['smf', 'mybb', 'phpbb'], true)
                || !in_array($source['driver'], ['mysql', 'mariadb'], true)
                || !in_array($source['host'], ['localhost', '127.0.0.1', '::1'], true)
                || !is_int($source['port']) || $source['port'] < 1 || $source['port'] > 65535
                || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $source['database']) !== 1
                || strlen($source['username']) > 128 || strlen($source['password']) > 512
                || preg_match('/^[A-Za-z0-9_]{0,48}$/D', $source['prefix']) !== 1
                || !in_array($source['charset'], ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true)
                || !is_array($source['attachment_roots']) || count($source['attachment_roots']) > 32
                || !is_array($source['user_map']) || count($source['user_map']) > 1
            ) {
                throw new RuntimeException('import_sources_invalid');
            }
            foreach ($source['attachment_roots'] as $folder => $directory) {
                if (
                    !is_string($directory) || strlen($directory) > 1024
                    || preg_match('/^[0-9]{1,8}$/D', (string) $folder) !== 1
                ) {
                    throw new RuntimeException('import_sources_invalid');
                }
            }
            foreach ($source['user_map'] as $old => $target) {
                if (preg_match('/^[1-9][0-9]{0,17}$/D', (string) $old) !== 1 || !is_int($target) || $target < 1) {
                    throw new RuntimeException('import_sources_invalid');
                }
            }
            $seen[$source['id']] = true;
            $result[] = $source;
        }
        return $result;
    }
}
