<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Modules\Importers\Domain\ImportSource;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Source copies. Since beta.11 the owner adds them in the admin panel: the password is sealed with a key derived
 * from the app secret and never shown again. The older private config/import-sources.json is still read.
 * The catalogue never returns credentials.
 */
final readonly class ImportSources
{
    public const ENGINES = ['smf', 'mybb', 'phpbb'];

    public const CHARSETS = ['UTF-8', 'Windows-1252', 'ISO-8859-1'];

    private const SEALED = 'v1:';

    public function __construct(private string $root, private Secret $appSecret, private ?Connection $db = null)
    {
    }

    public function catalogue(): array
    {
        $result = [];
        foreach ($this->read() as $entry) {
            $result[] = ['id' => $entry['id'], 'engine' => $entry['engine'], 'panel' => false,
                'database' => $entry['database'], 'prefix' => $entry['prefix'], 'preflight' => null];
        }
        foreach ($this->stored() as $entry) {
            $preflight = is_string($entry['preflight_json'] ?? null)
                ? json_decode($entry['preflight_json'], true, 8) : null;
            $result[] = ['id' => $entry['id'], 'engine' => $entry['engine'], 'panel' => true,
                'database' => $entry['database_name'], 'prefix' => $entry['prefix'],
                'preflight' => is_array($preflight) ? $preflight : null];
        }
        return $result;
    }

    public function open(string $id): ImportSource
    {
        try {
            foreach ($this->entries() as $entry) {
                if ($entry['id'] !== $id) {
                    continue;
                }
                return new SqlImportSource(
                    $this->connect($entry),
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

    /** A connection to a source that is not saved yet (the panel's check before saving). */
    public function connection(#[SensitiveParameter] array $entry): Connection
    {
        $entry = self::validated($entry);
        try {
            return $this->connect($entry);
        } catch (Throwable) {
            throw new RuntimeException('import_source_unavailable');
        }
    }

    /** Stores a checked source entered in the panel, with what its check found. Returns the new source ID. */
    public function save(#[SensitiveParameter] array $entry, array $preflight, int $actor): string
    {
        $entry = self::validated($entry);
        if ($entry['engine'] === '') {
            throw new RuntimeException('import_source_fields_invalid');
        }
        $id = 'panel-' . bin2hex(random_bytes(8));
        $now = gmdate('Y-m-d H:i:s') . '.000000';
        ($this->db ?? throw new RuntimeException('import_sources_invalid'))->insert('import_sources', [
            'id' => $id, 'engine' => $entry['engine'], 'host' => $entry['host'], 'port' => $entry['port'],
            'database_name' => $entry['database'], 'username' => $entry['username'],
            'password_sealed' => $this->seal($entry['password']), 'prefix' => $entry['prefix'],
            'charset' => $entry['charset'], 'attachment_root' => $entry['attachment_roots']['1'] ?? null,
            'preflight_json' => json_encode($preflight, JSON_THROW_ON_ERROR), 'created_by' => $actor,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        return $id;
    }

    /** Removes a panel source; imports already made from it keep their own records. */
    public function remove(string $id): void
    {
        $this->db?->delete('import_sources', ['id' => $id]);
    }

    /**
     * The panel's form fields, checked like the private file's entries; any host name is accepted because many
     * shared hosts keep the database on a separate server. An empty engine means "find it" (the check fills it in).
     */
    public static function validated(#[SensitiveParameter] array $entry): array
    {
        $root = trim((string) ($entry['attachment_root'] ?? ''));
        $entry = ['id' => (string) ($entry['id'] ?? 'panel-check'), 'engine' => (string) ($entry['engine'] ?? ''),
            'driver' => 'mysql', 'host' => trim((string) ($entry['host'] ?? '')),
            'port' => (int) ($entry['port'] ?? 3306), 'database' => trim((string) ($entry['database'] ?? '')),
            'username' => (string) ($entry['username'] ?? ''), 'password' => (string) ($entry['password'] ?? ''),
            'prefix' => trim((string) ($entry['prefix'] ?? '')),
            'charset' => (string) ($entry['charset'] ?? 'UTF-8'),
            'attachment_roots' => $root === '' ? [] : ['1' => rtrim($root, '/\\')], 'user_map' => []];
        if (
            ($entry['engine'] !== '' && !in_array($entry['engine'], self::ENGINES, true))
            || !self::host($entry['host'])
            || $entry['port'] < 1 || $entry['port'] > 65535
            || preg_match('/^[A-Za-z0-9_$-]{1,64}$/D', $entry['database']) !== 1
            || $entry['username'] === '' || strlen($entry['username']) > 128 || strlen($entry['password']) > 512
            || preg_match('/^[A-Za-z0-9_]{0,48}$/D', $entry['prefix']) !== 1
            || !in_array($entry['charset'], self::CHARSETS, true)
            || strlen($root) > 1024 || str_contains($root, "\0")
            || ($root !== '' && !str_starts_with($root, '/') && preg_match('~^[A-Za-z]:[\\\\/]~', $root) !== 1)
        ) {
            throw new RuntimeException('import_source_fields_invalid');
        }
        return $entry;
    }

    private static function host(string $host): bool
    {
        if ($host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        $label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
        return strlen($host) <= 253 && preg_match('/^' . $label . '(?:\.' . $label . ')*$/D', $host) === 1;
    }

    private function connect(#[SensitiveParameter] array $entry): Connection
    {
        return ConnectionFactory::create(new Config(
            $entry['driver'],
            $entry['host'],
            $entry['port'],
            $entry['database'],
            $entry['username'],
            new Secret($entry['password']),
            $this->appSecret
        ));
    }

    private function entries(): array
    {
        $entries = $this->read();
        foreach ($this->stored() as $row) {
            $root = $row['attachment_root'];
            $entries[] = ['id' => $row['id'], 'engine' => $row['engine'], 'driver' => 'mysql',
                'host' => $row['host'], 'port' => (int) $row['port'], 'database' => $row['database_name'],
                'username' => $row['username'], 'password' => $this->unseal((string) $row['password_sealed']),
                'prefix' => $row['prefix'], 'charset' => $row['charset'],
                'attachment_roots' => is_string($root) && $root !== '' ? ['1' => $root] : [], 'user_map' => []];
        }
        return $entries;
    }

    private function stored(): array
    {
        if ($this->db === null) {
            return [];
        }
        try {
            return $this->db->fetchAllAssociative('SELECT * FROM import_sources ORDER BY created_at, id LIMIT 32');
        } catch (Throwable) {
            // Before the 0054 migration ran there are no panel sources.
            return [];
        }
    }

    private function seal(#[SensitiveParameter] string $password): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::SEALED . base64_encode($nonce . sodium_crypto_secretbox($password, $nonce, $this->key()));
    }

    private function unseal(string $sealed): string
    {
        $raw = str_starts_with($sealed, self::SEALED) ? base64_decode(substr($sealed, strlen(self::SEALED)), true)
            : false;
        $plain = is_string($raw) && strlen($raw) > SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ? sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key()
        ) : false;
        if (!is_string($plain)) {
            // The app secret changed since the source was saved: the source has to be entered again.
            throw new RuntimeException('import_source_unavailable');
        }
        return $plain;
    }

    private function key(): string
    {
        return hash_hkdf('sha256', $this->appSecret->reveal(), SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'nolviko import');
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
