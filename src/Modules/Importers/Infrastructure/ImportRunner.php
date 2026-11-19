<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Modules\Importers\Domain\ImportSource;
use RuntimeException;

/**
 * The source side of an import step (ADR-038, 2026-11-20): asks the core import target where to read, reads that
 * batch from the source (outside the target's lock) and hands it to the target, which writes it with its checkpoint
 * in one transaction. From beta.12 on this loop lives in the importer extension; the target stays in the core.
 */
final class ImportRunner
{
    private readonly ImportTarget $target;

    public function __construct(
        Connection $db,
        private readonly ImportSource $source,
        ?ImportFileStore $files = null,
        ?InlineAttachments $inline = null,
    ) {
        $this->target = new ImportTarget($db, $files, $inline);
    }

    /** @param array<string, int|null> $totals The check's estimate of the source's records, shown in the report. */
    public function begin(int $actor, bool $acknowledgedScope, array $totals = []): string
    {
        if ($actor < 1 || !$acknowledgedScope) {
            throw new RuntimeException('import_confirmation_required');
        }
        return $this->target->begin(
            $this->source->identity(),
            $this->source->coverage(),
            $this->source->phases(),
            $actor,
            $acknowledgedScope,
            $totals,
        );
    }

    public function step(string $job, int $revision, int $limit = 25, int $milliseconds = 2000): array
    {
        if ($limit < 1 || $limit > 25) {
            throw new RuntimeException('import_batch_invalid');
        }
        $identity = $this->source->identity();
        $phases = $this->source->phases();
        $next = $this->target->next($job, $identity, $phases);
        if ($next['revision'] !== $revision) {
            throw new RuntimeException('import_checkpoint_conflict');
        }
        if ($next['complete']) {
            return $this->target->read($job);
        }
        return $this->target->apply(
            $job,
            $revision,
            $identity,
            $phases,
            $this->source->rows($next['phase'], $next['cursor'], $limit),
            $this->source->asset(...),
            $milliseconds,
        );
    }

    public function read(string $id): array
    {
        return $this->target->read($id);
    }
}
