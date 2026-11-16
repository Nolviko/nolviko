<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Modules\Importers\Domain\ImportSource;
use RuntimeException;

/** One locked target transaction per bounded batch; data/map/checkpoint commit together or roll back together. */
final class ImportRunner
{
    public function __construct(
        private readonly Connection $db,
        private readonly ImportSource $source,
        private readonly ?ImportFileStore $files = null,
        private readonly ?InlineAttachments $inline = null,
    ) {
    }

    public function begin(int $actor, bool $acknowledgedScope): string
    {
        if ($actor < 1 || !$acknowledgedScope || $this->db->isTransactionActive()) {
            throw new RuntimeException('import_confirmation_required');
        }
        $identity = $this->source->identity();
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
        $key = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $existing = $this->db->fetchOne('SELECT id FROM import_jobs WHERE source_key = ?', [$key]);
        if (is_string($existing)) {
            return $existing;
        }
        $coverage = $this->source->coverage();
        $report = json_encode(
            ['identity' => $identity, 'coverage' => $coverage,
            'scope' => 'users_content_private_archives_files_polls', 'quarantined' => true, 'counts'
                => [], 'verified_counts' => []],
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

    public function step(string $job, int $revision, int $limit = 25, int $milliseconds = 2000): array
    {
        if (
            preg_match('/^[a-f0-9]{32}$/D', $job) !== 1 || $revision < 0 || $limit < 1 || $limit > 25
            || $milliseconds < 10 || $milliseconds > 2000 || $this->db->isTransactionActive()
        ) {
            throw new RuntimeException('import_batch_invalid');
        }
        $identity = $this->source->identity();
        $deadline = hrtime(true) + $milliseconds * 1000000;
        return $this->db->transactional(function () use ($job, $revision, $limit, $identity, $deadline): array {
            $run = $this->db->fetchAssociative('SELECT * FROM import_jobs WHERE id = ? FOR UPDATE', [$job]);
            if (
                $run === false || $revision !== (int) $run['revision']
                || !hash_equals($run['source_key'], hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)))
            ) {
                throw new RuntimeException('import_checkpoint_conflict');
            }
            if ($run['state'] === 'completed') {
                return self::status($run);
            }
            $phases = $this->source->phases();
            $index = (int) $run['phase'];
            $count = count($phases);
            $reparent = $index === $count;
            $moved = $index === $count + 1;
            $rewriting = $index === $count + 2;
            $verifying = $index >= $count + 3;
            $phase = $reparent ? 'forums' : ($moved ? 'threads'
                : ($rewriting ? 'posts' : $phases[$verifying ? $index - $count - 3 : $index]));
            $rows = $this->source->rows($phase, (string) $run['cursor_id'], $limit);
            $writer = new ImportWriter(
                $this->db,
                $identity,
                $this->files === null ? null : new ImportAuxWriter($this->db, $this->source, $this->files)
            );
            $report = json_decode($run['report_json'], true, 12, JSON_THROW_ON_ERROR);
            if ($rows === []) {
                if (
                    $verifying && (int) ($report['counts'][$phase] ?? 0)
                    !== (int) ($report['verified_counts'][$phase] ?? 0)
                ) {
                    throw new RuntimeException('import_count_mismatch');
                }
                $run['phase'] = ++$index;
                $run['cursor_id'] = '';
                if ($index === $count * 2 + 3) {
                    $run['state'] = 'completed';
                    VersionCounter::bump($this->db, VersionCounter::PERMISSION_RULES);
                }
            } else {
                foreach ($rows as $row) {
                    if (
                        !is_array($row) || !isset($row['id']) || !is_int($row['id'])
                        || !is_string($row['_key'] ?? null)
                        || strcmp($row['_key'], (string) $run['cursor_id']) <= 0
                    ) {
                        throw new RuntimeException('import_source_order_invalid');
                    }
                    if ($reparent) {
                        $writer->reparent($job, $row['id']);
                    } elseif ($moved) {
                        $writer->moved($job, $row['id']);
                    } elseif ($rewriting) {
                        $writer->inline($job, $row, $run['engine'], $this->inline);
                    } elseif ($verifying) {
                        $writer->verify($job, $phase, $row);
                        $report['verified_counts'][$phase] = ($report['verified_counts'][$phase] ?? 0) + 1;
                        ++$run['verified'];
                    } else {
                        $writer->write($job, $phase, $row, $run['engine']);
                        $report['counts'][$phase] = ($report['counts'][$phase] ?? 0) + 1;
                        ++$run['processed'];
                    }
                    $run['cursor_id'] = $row['_key'];
                    if (hrtime(true) >= $deadline) {
                        break;
                    }
                }
            }
            ++$run['revision'];
            $this->db->update('import_jobs', ['phase' => $run['phase'], 'cursor_id' => $run['cursor_id'],
                'revision' => $run['revision'], 'processed' => $run['processed'], 'verified' => $run['verified'],
                'state' => $run['state'], 'report_json' => json_encode($report, JSON_THROW_ON_ERROR),
                'updated_at' => self::now()], ['id' => $job]);
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
