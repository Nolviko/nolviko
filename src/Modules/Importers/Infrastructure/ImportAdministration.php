<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Doctrine\DBAL\Connection;
use RuntimeException;

/** Administration projection exposes bounded counts/mapping reasons, never row bodies, hashes or credentials. */
final readonly class ImportAdministration
{
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

    public function begin(string $source, int $actor, bool $acknowledged): string
    {
        return (new ImportRunner(
            $this->db,
            $this->sources->open($source),
            $this->files,
            $this->inline
        ))->begin($actor, $acknowledged);
    }

    public function step(string $job, int $revision): array
    {
        $report = $this->report($job);
        $identity = $report['identity'];
        $source = $this->sources->open($identity['source_id']);
        return (new ImportRunner($this->db, $source, $this->files, $this->inline))->step($job, $revision);
    }

    public function recent(): array
    {
        return $this->db->fetchAllAssociative('SELECT id, engine, source_version, state, processed, verified, '
            . 'revision, phase, created_at FROM import_jobs ORDER BY created_at DESC, id DESC LIMIT 20');
    }

    public function job(string $job): array
    {
        self::id($job);
        $row = $this->db->fetchAssociative('SELECT id, engine, source_version, state, processed, verified, '
            . 'revision, phase FROM import_jobs WHERE id = ?', [$job]);
        if ($row === false) {
            throw new RuntimeException('import_job_missing');
        }
        return $row;
    }

    public function report(string $job): array
    {
        self::id($job);
        $json = $this->db->fetchOne('SELECT report_json FROM import_jobs WHERE id = ?', [$job]);
        if (!is_string($json) || strlen($json) > 32768) {
            throw new RuntimeException('import_report_missing');
        }
        $report = json_decode($json, true, 12, JSON_THROW_ON_ERROR);
        return ['identity' => $report['identity'], 'coverage' => $report['coverage'],
            'counts' => $report['counts'], 'verified_counts' => $report['verified_counts'],
                'quarantined' => true, 'notes' => $this->notes($job)];
    }

    private function notes(string $job): array
    {
        $rows = $this->db->fetchAllAssociative('SELECT entity, source_id, reason FROM import_map '
            . 'WHERE job_id = ? AND reason IS NOT NULL '
            . 'ORDER BY reason, entity, source_id LIMIT 100', [$job]);
        $notes = [];
        foreach ($rows as $row) {
            $notes[] = ['entity' => $row['entity'], 'source_id' => $row['source_id'],
                'reason' => $row['reason']];
        }
        return $notes;
    }

    private static function id(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            throw new RuntimeException('import_job_missing');
        }
    }
}
