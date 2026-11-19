<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nolviko\Core\Database\VersionCounter;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * The admin panel's view of imports. It exposes bounded counts and mapping reasons, never row bodies, hashes or
 * credentials.
 *
 * Since beta.11: sources are added and checked in the panel, one request runs several small batches (each its own
 * transaction with its checkpoint), the report lists per-kind counts with skipped and to-review records and pages
 * through all notes, a machine-readable summary can be downloaded, and the owner opens the imported content with
 * an explicit "publish" decision.
 */
final readonly class ImportAdministration
{
    public const NOTES_PER_PAGE = 100;

    /** Time one request spends on batches; well below the 30-second limit of most shared hosts. */
    public const RUN_SECONDS = 5.0;

    public function __construct(
        private Connection $db,
        private ImportSources $sources,
        private ImportFileStore $files,
        private InlineAttachments $inline
    ) {
    }

    public function sources(): array
    {
        return $this->sources->catalogue();
    }

    /** Connects to a source that is not saved yet and reports what it holds. */
    public function check(#[SensitiveParameter] array $fields): array
    {
        $connection = $this->sources->connection($fields);
        try {
            return (new ImportPreflight($connection))->inspect($fields);
        } catch (RuntimeException $failure) {
            throw $failure;
        } catch (Throwable) {
            // Wrong host, user, password or database name: no driver text reaches the page.
            throw new RuntimeException('import_source_unavailable');
        } finally {
            $connection->close();
        }
    }

    /**
     * Checks a source and saves it when it is ready, with the forum, prefix and character set the check found.
     *
     * @return array{id: string|null, preflight: array}
     */
    public function add(#[SensitiveParameter] array $fields, int $actor): array
    {
        $preflight = $this->check($fields);
        if (!ImportPreflight::ready($preflight)) {
            return ['id' => null, 'preflight' => $preflight];
        }
        $fields['engine'] = $preflight['engine'];
        $fields['prefix'] = $preflight['prefix'];
        if ($preflight['charset'] !== null) {
            $fields['charset'] = $preflight['charset'];
        }
        return ['id' => $this->sources->save($fields, $preflight, $actor), 'preflight' => $preflight];
    }

    public function removeSource(string $id): void
    {
        $this->sources->remove($id);
    }

    public function begin(string $source, int $actor, bool $acknowledged): string
    {
        $totals = [];
        foreach ($this->sources->catalogue() as $entry) {
            if ($entry['id'] === $source && is_array($entry['preflight']['totals'] ?? null)) {
                $totals = $entry['preflight']['totals'];
            }
        }
        return (new ImportRunner(
            $this->db,
            $this->sources->open($source),
            $this->files,
            $this->inline
        ))->begin($actor, $acknowledged, $totals);
    }

    public function step(string $job, int $revision): array
    {
        return $this->runner($job)->step($job, $revision);
    }

    /**
     * Runs batches until the job is complete or this request's time or memory share is used up. Each batch commits
     * its records and checkpoint together, so a request that is cut off loses at most the batch in progress.
     */
    public function run(string $job, int $revision, float $seconds = self::RUN_SECONDS): array
    {
        $runner = $this->runner($job);
        $until = microtime(true) + $seconds;
        $memory = self::memoryLimit();
        do {
            $status = $runner->step($job, $revision);
            $revision = $status['revision'];
        } while (
            !$status['complete'] && microtime(true) < $until
            && ($memory === null || memory_get_usage(true) < $memory * 0.7)
        );
        return $status;
    }

    public function recent(): array
    {
        return $this->db->fetchAllAssociative('SELECT id, engine, source_version, state, processed, verified, '
            . 'revision, phase, created_at, published_at FROM import_jobs ORDER BY created_at DESC, id DESC LIMIT 20');
    }

    public function job(string $job): array
    {
        self::id($job);
        $row = $this->db->fetchAssociative('SELECT id, engine, source_version, state, processed, verified, '
            . 'revision, phase, published_at FROM import_jobs WHERE id = ?', [$job]);
        if ($row === false) {
            throw new RuntimeException('import_job_missing');
        }
        return $row;
    }

    /**
     * Identity, coverage and per-kind counts. When the job is complete the counts also hold what was skipped and
     * what needs review (one grouped read over the job's mapping), and the notes are paged.
     */
    public function report(string $job, int $page = 1): array
    {
        $run = $this->job($job);
        $report = $this->stored($job);
        $complete = $run['state'] === 'completed';
        $counts = [];
        foreach (
            $this->db->fetchAllAssociative('SELECT entity, processed, verified FROM import_counts '
                . 'WHERE job_id = ? ORDER BY entity', [$job]) as $row
        ) {
            $counts[$row['entity']] = ['processed' => (int) $row['processed'], 'verified' => (int) $row['verified'],
                'skipped' => null, 'review' => null];
        }
        // Jobs from before beta.11 that have not run a batch since still keep their counters in the report.
        foreach ($report['counts'] ?? [] as $entity => $processed) {
            $counts[$entity] ??= ['processed' => (int) $processed,
                'verified' => (int) ($report['verified_counts'][$entity] ?? 0), 'skipped' => null, 'review' => null];
        }
        $notesTotal = 0;
        if ($complete) {
            foreach (
                $this->db->fetchAllAssociative('SELECT entity, '
                    . "SUM(CASE WHEN target_id = '0' THEN 1 ELSE 0 END) AS skipped, "
                    . 'SUM(CASE WHEN reason IS NOT NULL THEN 1 ELSE 0 END) AS review '
                    . 'FROM import_map WHERE job_id = ? GROUP BY entity', [$job]) as $row
            ) {
                $counts[$row['entity']] ??= ['processed' => 0, 'verified' => 0];
                $counts[$row['entity']]['skipped'] = (int) $row['skipped'];
                $counts[$row['entity']]['review'] = (int) $row['review'];
                $notesTotal += (int) $row['review'];
            }
        } else {
            $notesTotal = (int) $this->db->fetchOne('SELECT COUNT(*) FROM import_map '
                . 'WHERE job_id = ? AND reason IS NOT NULL', [$job]);
        }
        $pages = max(1, (int) ceil($notesTotal / self::NOTES_PER_PAGE));
        $page = min(max(1, $page), $pages);
        return ['identity' => $report['identity'], 'coverage' => $report['coverage'],
            'totals' => $report['totals'] ?? [], 'counts' => $counts, 'complete' => $complete,
            'published' => $run['published_at'] !== null, 'quarantined' => $run['published_at'] === null,
            'notes' => $this->notes($job, $page), 'notes_total' => $notesTotal, 'notes_page' => $page,
            'notes_pages' => $pages];
    }

    /**
     * The machine-readable summary: versions, coverage and counts only. Member data, private message content,
     * source credentials and the source's table prefix stay out.
     */
    public function export(string $job): array
    {
        $run = $this->job($job);
        $report = $this->report($job);
        $reasons = [];
        $grouped = $this->db->fetchAllAssociative('SELECT entity, reason, COUNT(*) AS records FROM import_map '
            . 'WHERE job_id = ? AND reason IS NOT NULL GROUP BY entity, reason ORDER BY entity, reason', [$job]);
        foreach ($grouped as $row) {
            $reasons[] = ['entity' => $row['entity'], 'reason' => $row['reason'], 'records' => (int) $row['records']];
        }
        return ['format' => 'nolviko-import-report', 'version' => 1, 'job' => $run['id'],
            'source' => ['engine' => $run['engine'], 'version' => $run['source_version'],
                'charset' => $report['identity']['charset'] ?? null],
            'target' => ['database' => self::platform($this->db)],
            'state' => $run['state'], 'published' => $report['published'], 'estimated_source' => $report['totals'],
            'counts' => $report['counts'], 'reasons' => $reasons,
            'coverage' => array_map(
                static fn(array $table): array => ['table' => $table['table'], 'mapping' => $table['mapping'],
                    'estimated_rows' => $table['estimated_rows'] ?? null],
                $report['coverage']
            )];
    }

    /**
     * The owner's decision to open a verified import: imported forums drop the "never view" rules the import put
     * on them (they then follow their parent and the site default), and when asked, imported members whose
     * passwords carried over can sign in. Members who must reset their password stay as they are.
     */
    public function publish(string $job, bool $activateMembers): array
    {
        $run = $this->job($job);
        if ($run['state'] !== 'completed') {
            throw new RuntimeException('import_not_complete');
        }
        if ($run['published_at'] !== null) {
            return ['forums' => 0, 'members' => 0];
        }
        return $this->db->transactional(function () use ($job, $activateMembers): array {
            $locked = $this->db->fetchOne('SELECT published_at FROM import_jobs WHERE id = ? FOR UPDATE', [$job]);
            if ($locked !== null) {
                return ['forums' => 0, 'members' => 0];
            }
            $forums = array_map(intval(...), $this->db->fetchFirstColumn("SELECT target_id FROM import_map "
                . "WHERE job_id = ? AND entity IN ('forums', 'categories') AND target_id <> '0'", [$job]));
            $opened = 0;
            foreach (array_chunk($forums, 500) as $chunk) {
                $opened += (int) $this->db->executeStatement(
                    "DELETE FROM forum_permissions WHERE forum_id IN (?) AND permission_key = 'forum.view' "
                        . "AND value = 'never'",
                    [$chunk],
                    [ArrayParameterType::INTEGER]
                );
            }
            $members = 0;
            if ($activateMembers) {
                $after = '';
                do {
                    $rows = $this->db->fetchAllAssociative("SELECT source_id, target_id FROM import_map "
                        . "WHERE job_id = ? AND entity = 'users' AND reason IS NULL AND source_id > ? "
                        . 'ORDER BY source_id LIMIT 1000', [$job, $after]);
                    if ($rows === []) {
                        break;
                    }
                    $after = (string) end($rows)['source_id'];
                    $members += (int) $this->db->executeStatement(
                        "UPDATE users SET status = 'active' WHERE id IN (?) AND status = 'pending_approval'",
                        [array_map(intval(...), array_column($rows, 'target_id'))],
                        [ArrayParameterType::INTEGER]
                    );
                } while (count($rows) === 1000);
            }
            $this->db->update('import_jobs', ['published_at' => gmdate('Y-m-d H:i:s') . '.000000'], ['id' => $job]);
            VersionCounter::bump($this->db, VersionCounter::PERMISSION_RULES);
            return ['forums' => count($forums), 'rules' => $opened, 'members' => $members];
        });
    }

    private function runner(string $job): ImportRunner
    {
        $identity = $this->stored($job)['identity'];
        return new ImportRunner($this->db, $this->sources->open($identity['source_id']), $this->files, $this->inline);
    }

    private function stored(string $job): array
    {
        self::id($job);
        $json = $this->db->fetchOne('SELECT report_json FROM import_jobs WHERE id = ?', [$job]);
        if (!is_string($json) || strlen($json) > 65535) {
            throw new RuntimeException('import_report_missing');
        }
        return json_decode($json, true, 12, JSON_THROW_ON_ERROR);
    }

    private function notes(string $job, int $page): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT entity, source_id, reason FROM import_map '
            . 'WHERE job_id = ? AND reason IS NOT NULL '
            . 'ORDER BY reason, entity, source_id LIMIT ' . self::NOTES_PER_PAGE . ' OFFSET '
            . (($page - 1) * self::NOTES_PER_PAGE), [$job]);
        $notes = [];
        foreach ($rows as $row) {
            $notes[] = ['entity' => $row['entity'], 'source_id' => implode(':', array_map(
                static fn(string $part): string => ltrim($part, '0') ?: '0',
                explode(':', (string) $row['source_id'])
            )),
                'reason' => $row['reason']];
        }
        return $notes;
    }

    /** The forum's database engine and server version for the report, e.g. "MySQL 8.4.3". */
    private static function platform(Connection $db): string
    {
        try {
            $version = (string) $db->fetchOne('SELECT VERSION()');
        } catch (Throwable) {
            $version = '';
        }
        $family = match (true) {
            stripos($version, 'mariadb') !== false => 'MariaDB',
            $db->getDatabasePlatform() instanceof PostgreSQLPlatform => 'PostgreSQL',
            default => 'MySQL',
        };
        return trim($family . ' ' . (preg_match('/[0-9]+(?:[.][0-9]+){1,2}/', $version, $m) === 1 ? $m[0] : ''));
    }

    private static function memoryLimit(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '' || $limit === '-1') {
            return null;
        }
        $value = (int) $limit;
        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private static function id(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new RuntimeException('import_job_missing');
        }
    }
}
