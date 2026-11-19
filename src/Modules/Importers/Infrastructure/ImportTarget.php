<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Modules\Importers\Domain\ImportAsset;
use Nolviko\Modules\Importers\Domain\ImportRecords;
use RuntimeException;

/**
 * The core side of an import (ADR-038, contract 1, 2026-11-20): it owns the job, the checkpoint, the ID map, the
 * counters, the writes into the forum's tables, the verification pass, quarantine and publishing. A source (the
 * importer extension from beta.12 on) only answers "which records come next" (`next()`) and hands them over
 * (`apply()`); it never writes the forum's tables itself.
 *
 * One locked transaction per batch: records, map, counters and checkpoint commit together or not at all. The
 * source's records are read before the lock; `apply()` refuses them when another request moved the checkpoint in
 * between (`import_checkpoint_conflict`), so a record is never written twice.
 *
 * After the source's own phases come four core passes over the same records: forums re-parented, moved threads
 * linked, inline attachments rewritten, then every phase read again and verified.
 */
final class ImportTarget
{
    public const CONTRACT = 1;

    public function __construct(
        private readonly Connection $db,
        private readonly ?ImportFileStore $files = null,
        private readonly ?InlineAttachments $inline = null,
    ) {
    }

    /**
     * Opens the job for a source, or returns the open one for the same source.
     *
     * @param array<string, mixed> $identity engine, version, source_id, prefix, charset, timestamps, user_map
     * @param list<array<string, mixed>> $coverage the source's tables and whether they are imported
     * @param list<string> $phases the source's record phases in order
     * @param array<string, int|null> $totals the check's estimate of the source's records
     */
    public function begin(
        array $identity,
        array $coverage,
        array $phases,
        int $actor,
        bool $acknowledgedScope,
        array $totals = [],
    ): string {
        if ($actor < 1 || !$acknowledgedScope || $this->db->isTransactionActive()) {
            throw new RuntimeException('import_confirmation_required');
        }
        self::assertPhases($phases);
        foreach ($identity['user_map'] ?? [] as $sourceUser => $targetUser) {
            if (
                !is_int($targetUser) || $targetUser !== $actor || (int) $sourceUser < 1
                || ($identity['engine'] === 'phpbb' && (int) $sourceUser === 1)
            ) {
                throw new RuntimeException('import_existing_user_map_denied');
            }
        }
        if (count($identity['user_map'] ?? []) > 1) {
            throw new RuntimeException('import_existing_user_map_denied');
        }
        if (
            !is_string($identity['engine'] ?? null) || !is_string($identity['version'] ?? null)
            || !in_array($identity['engine'], ['smf', 'mybb', 'phpbb'], true) || strlen($identity['version']) > 32
        ) {
            throw new RuntimeException('import_source_invalid');
        }
        $key = self::key($identity);
        $existing = $this->db->fetchOne('SELECT id FROM import_jobs WHERE source_key = ?', [$key]);
        if (is_string($existing)) {
            return $existing;
        }
        $report = json_encode(
            ['identity' => $identity, 'coverage' => $coverage, 'phases' => $phases,
            'scope' => 'users_content_private_archives_files_polls', 'quarantined' => true,
                'totals' => array_slice($totals, 0, 16)],
            JSON_THROW_ON_ERROR
        );
        if (strlen($report) > 32768) {
            throw new RuntimeException('import_report_budget');
        }
        $id = bin2hex(random_bytes(16));
        try {
            $this->db->insert('import_jobs', ['id' => $id, 'source_key' => $key, 'actor_id' => $actor,
                'engine' => $identity['engine'], 'source_version' => $identity['version'], 'report_json' => $report,
                'created_at' => self::now(), 'updated_at' => self::now()]);
        } catch (UniqueConstraintViolationException) {
            return (string) $this->db->fetchOne('SELECT id FROM import_jobs WHERE source_key = ?', [$key]);
        }
        return $id;
    }

    /**
     * Where the source has to read next: the phase, the cursor after which records come, and the revision
     * `apply()` must be called with. Read without a lock; `apply()` checks the revision again under its lock.
     *
     * @param list<string> $phases
     * @return array{phase: string, cursor: string, revision: int, complete: bool}
     */
    public function next(string $job, array $identity, array $phases): array
    {
        $run = $this->job($job, $identity, $phases);
        return ['phase' => self::phase($phases, (int) $run['phase']), 'cursor' => (string) $run['cursor_id'],
            'revision' => (int) $run['revision'], 'complete' => $run['state'] === 'completed'];
    }

    /**
     * Writes one batch of the phase `next()` named. An empty batch ends that phase.
     *
     * @param list<string> $phases
     * @param list<array<string, mixed>> $records
     * @param Closure(array): ?ImportAsset $asset the original file of an attachment record
     */
    public function apply(
        string $job,
        int $revision,
        array $identity,
        array $phases,
        array $records,
        ?Closure $asset = null,
        int $milliseconds = 2000,
    ): array {
        if (
            preg_match('/^[a-f0-9]{32}$/D', $job) !== 1 || $revision < 0 || count($records) > 25
            || $milliseconds < 10 || $milliseconds > 2000 || $this->db->isTransactionActive()
        ) {
            throw new RuntimeException('import_batch_invalid');
        }
        self::assertPhases($phases);
        $deadline = hrtime(true) + $milliseconds * 1000000;
        return $this->db->transactional(function () use (
            $job,
            $revision,
            $identity,
            $phases,
            $records,
            $asset,
            $deadline,
        ): array {
            $run = $this->db->fetchAssociative('SELECT * FROM import_jobs WHERE id = ? FOR UPDATE', [$job]);
            if (
                $run === false || $revision !== (int) $run['revision']
                || !hash_equals($run['source_key'], self::key($identity))
            ) {
                throw new RuntimeException('import_checkpoint_conflict');
            }
            if ($run['state'] === 'completed') {
                return self::status($run);
            }
            $report = json_decode($run['report_json'], true, 12, JSON_THROW_ON_ERROR);
            if (($report['phases'] ?? $phases) !== $phases) {
                throw new RuntimeException('import_source_changed');
            }
            $index = (int) $run['phase'];
            $count = count($phases);
            $reparent = $index === $count;
            $moved = $index === $count + 1;
            $rewriting = $index === $count + 2;
            $verifying = $index >= $count + 3;
            $phase = self::phase($phases, $index);
            $writer = new ImportWriter(
                $this->db,
                $identity,
                $this->files === null ? null : new ImportAuxWriter(
                    $this->db,
                    $asset ?? static fn(array $row): never => throw new RuntimeException('import_attachment_missing'),
                    $this->files
                )
            );
            $reportChanged = $this->moveLegacyCounts($job, $report);
            $delta = [];
            if ($records === []) {
                if ($verifying) {
                    $tally = $this->db->fetchAssociative('SELECT processed, verified FROM import_counts '
                        . 'WHERE job_id = ? AND entity = ?', [$job, $phase]);
                    if ($tally !== false && (int) $tally['processed'] !== (int) $tally['verified']) {
                        throw new RuntimeException('import_count_mismatch');
                    }
                }
                $run['phase'] = ++$index;
                $run['cursor_id'] = '';
                if ($index === $count * 2 + 3) {
                    $run['state'] = 'completed';
                    VersionCounter::bump($this->db, VersionCounter::PERMISSION_RULES);
                }
            } else {
                foreach ($records as $row) {
                    if (!is_array($row)) {
                        throw new RuntimeException('import_source_order_invalid');
                    }
                    ImportRecords::assert($row, (string) $run['cursor_id']);
                    if ($reparent) {
                        $writer->reparent($job, $row['id']);
                    } elseif ($moved) {
                        $writer->moved($job, $row['id']);
                    } elseif ($rewriting) {
                        $writer->inline($job, $row, $this->inline);
                    } elseif ($verifying) {
                        $writer->verify($job, $phase, $row);
                        $delta[$phase]['verified'] = ($delta[$phase]['verified'] ?? 0) + 1;
                        ++$run['verified'];
                    } else {
                        $writer->write($job, $phase, $row, $run['engine']);
                        $delta[$phase]['processed'] = ($delta[$phase]['processed'] ?? 0) + 1;
                        ++$run['processed'];
                    }
                    $run['cursor_id'] = $row['_key'];
                    if (hrtime(true) >= $deadline) {
                        break;
                    }
                }
            }
            foreach ($delta as $entity => $added) {
                $this->count($job, $entity, $added['processed'] ?? 0, $added['verified'] ?? 0);
            }
            ++$run['revision'];
            $update = ['phase' => $run['phase'], 'cursor_id' => $run['cursor_id'],
                'revision' => $run['revision'], 'processed' => $run['processed'], 'verified' => $run['verified'],
                'state' => $run['state'], 'updated_at' => self::now()];
            if ($reportChanged) {
                $update['report_json'] = json_encode($report, JSON_THROW_ON_ERROR);
            }
            $this->db->update('import_jobs', $update, ['id' => $job]);
            return self::status($run);
        });
    }

    public function read(string $id): array
    {
        $run = $this->db->fetchAssociative('SELECT * FROM import_jobs WHERE id = ?', [$id]);
        if ($run === false) {
            throw new RuntimeException('import_job_missing');
        }
        return self::status($run);
    }

    /** The job, checked against the source's identity and against the phases it was started with. */
    private function job(string $job, array $identity, array $phases): array
    {
        self::assertPhases($phases);
        $run = preg_match('/^[a-f0-9]{32}$/D', $job) === 1
            ? $this->db->fetchAssociative('SELECT * FROM import_jobs WHERE id = ?', [$job]) : false;
        if ($run === false) {
            throw new RuntimeException('import_job_missing');
        }
        if (!hash_equals($run['source_key'], self::key($identity))) {
            throw new RuntimeException('import_checkpoint_conflict');
        }
        $stored = json_decode($run['report_json'], true, 12, JSON_THROW_ON_ERROR)['phases'] ?? null;
        if ($stored !== null && $stored !== $phases) {
            // Jobs started before beta.12 did not record their phases; later ones keep them fixed.
            throw new RuntimeException('import_source_changed');
        }
        return $run;
    }

    /** The source phase whose records the job's current step reads (the core passes reuse some of them). */
    private static function phase(array $phases, int $index): string
    {
        $count = count($phases);
        return match (true) {
            $index === $count => 'forums',
            $index === $count + 1 => 'threads',
            $index === $count + 2 => 'posts',
            $index > $count + 2 => $phases[min($index - $count - 3, $count - 1)],
            default => $phases[$index],
        };
    }

    private static function assertPhases(array $phases): void
    {
        if (
            !array_is_list($phases) || count($phases) < 3 || count($phases) > 32
            || array_diff(['forums', 'threads', 'posts'], $phases) !== []
            || count(array_unique($phases)) !== count($phases)
        ) {
            throw new RuntimeException('import_source_invalid');
        }
        foreach ($phases as $phase) {
            if (!is_string($phase) || preg_match('/^[a-z][a-z_]{0,23}$/D', $phase) !== 1) {
                throw new RuntimeException('import_source_invalid');
            }
        }
    }

    private static function key(array $identity): string
    {
        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    /**
     * Jobs started before beta.11 kept their counters in the report; they move to import_counts once, so the
     * report stops growing and can never block a resume.
     */
    private function moveLegacyCounts(string $job, array &$report): bool
    {
        if (!isset($report['counts']) && !isset($report['verified_counts'])) {
            return false;
        }
        $entities = array_unique([
            ...array_keys($report['counts'] ?? []),
            ...array_keys($report['verified_counts'] ?? []),
        ]);
        foreach ($entities as $entity) {
            $this->count(
                $job,
                (string) $entity,
                (int) ($report['counts'][$entity] ?? 0),
                (int) ($report['verified_counts'][$entity] ?? 0)
            );
        }
        unset($report['counts'], $report['verified_counts']);
        return true;
    }

    private function count(string $job, string $entity, int $processed, int $verified): void
    {
        if ($processed === 0 && $verified === 0) {
            // MySQL reports an UPDATE that changes nothing as 0 rows, which would look like a missing row.
            return;
        }
        $changed = $this->db->executeStatement(
            'UPDATE import_counts SET processed = processed + ?, verified = verified + ? '
                . 'WHERE job_id = ? AND entity = ?',
            [$processed, $verified, $job, $entity]
        );
        if ($changed === 0) {
            $this->db->insert('import_counts', ['job_id' => $job, 'entity' => $entity, 'processed' => $processed,
                'verified' => $verified]);
        }
    }

    private static function status(array $run): array
    {
        return ['id' => $run['id'], 'phase' => (int) $run['phase'], 'cursor' => (string) $run['cursor_id'],
            'revision' => (int) $run['revision'], 'processed' => (int) $run['processed'],
            'verified' => (int) $run['verified'], 'complete' => $run['state'] === 'completed'];
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s') . '.000000';
    }
}
