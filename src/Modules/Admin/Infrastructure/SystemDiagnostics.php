<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use RuntimeException;
use Throwable;

/** An explicit allowlist, not a dump of configuration, environment, SQL errors or user/queue payloads. */
final readonly class SystemDiagnostics
{
    public function __construct(
        private Connection $db,
        private string $root,
        private string $storage,
        private ?SiteUrl $site = null
    ) {
    }

    public function snapshot(): array
    {
        $result = ['format' => 1, 'generated_at' => gmdate('c'), 'php' => PHP_VERSION,
            'memory_limit' => (string) ini_get('memory_limit'),
            'extensions' => [], 'database' => ['status' => 'unavailable'],
            'storage_writable' => is_dir($this->storage) && is_writable($this->storage),
            'gd_available' => extension_loaded('gd'), 'maintenance'
                => file_exists($this->root . '/config/maintenance.lock'),
            'seo' => ['configured' => $this->site?->configured() ?? false, 'state' => 'unavailable'],
            'migrations' => [], 'tasks' => [], 'imports' => [], 'updates' => []];
        foreach (
            ['pdo_mysql', 'pdo_pgsql', 'intl', 'mbstring', 'sodium', 'zip', 'fileinfo', 'gd',
            'xmlreader'] as $extension
        ) {
            $result['extensions'][$extension] = extension_loaded($extension);
        }
        try {
            $version = (string) $this->db->fetchOne('SELECT VERSION()');
            preg_match('/([0-9]+\.[0-9]+\.[0-9]+)/', str_replace('5.5.5-', '', $version), $match);
            $result['database'] = ['status' => 'ready', 'engine' => str_contains(
                $version,
                'MariaDB'
            ) ? 'mariadb'
                : ($this->db->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'postgresql' : 'mysql'),
                'version' => $match[1] ?? 'unknown'];
            foreach (['applied', 'applying', 'failed'] as $status) {
                $result['migrations'][$status] = $this->bounded('migration_history', 'status', $status);
            }
            foreach (['pending', 'running', 'failed'] as $status) {
                $result['tasks'][$status] = $this->bounded('outbox_events', 'status', $status);
            }
            foreach (['running', 'completed'] as $status) {
                $result['imports'][$status] = $this->bounded('import_jobs', 'state', $status);
            }
            try {
                $seo = $this->db->fetchOne('SELECT state FROM seo_sitemap_runs WHERE active_slot = 1');
                $result['seo']['state'] = in_array(
                    $seo,
                    ['running', 'completed', 'capacity'],
                    true
                ) ? $seo : 'idle';
            } catch (Throwable) {
                // A not-yet-expanded optional module does not make the core database unavailable.
            }
            foreach (['running', 'recoverable', 'manual_attention'] as $status) {
                $result['updates'][$status] = $this->bounded('update_runs', 'run_condition', $status);
            }
        } catch (Throwable) {
            $result['database']['status'] = 'unavailable';
            // No exception text, connection parameters or partially decoded records are copied into the bundle.
        }
        return $result;
    }

    public function bundle(): string
    {
        $json = json_encode(
            $this->snapshot(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (strlen($json) > 65536) {
            throw new RuntimeException('support_bundle_budget');
        }
        return $json . "\n";
    }

    private function bounded(string $table, string $column, string $status): array
    {
        $allowed = ['migration_history' => 'status', 'outbox_events' => 'status',
            'import_jobs' => 'state', 'update_runs' => 'run_condition'];
        if (($allowed[$table] ?? null) !== $column) {
            throw new RuntimeException('diagnostics_projection_invalid');
        }
        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM (SELECT 1 FROM ' . $table . ' WHERE '
            . $column . ' = ?' . ($table === 'update_runs' ? ' AND active_slot = 1' : '')
            . ' LIMIT 1001) limited_diagnostics', [$status]);
        return ['count' => min(1000, $count), 'capped' => $count > 1000];
    }
}
