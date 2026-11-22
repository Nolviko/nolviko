<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0081 (beta.18): `thread_polls.allow_change`, the poll setting that lets members change or withdraw their vote.
 * Off for every existing poll, so nothing changes until the poll's owner switches it on.
 */
final class PollVoteChange implements Migration
{
    public function id(): string
    {
        return '0081_poll_vote_change';
    }

    public function phase(): MigrationPhase
    {
        return MigrationPhase::Expand;
    }

    public function statements(string $backend): array
    {
        if ($backend !== 'mysql') {
            throw new MigrationException('migration_backend_unsupported:' . $backend);
        }

        return ['ALTER TABLE thread_polls ADD COLUMN allow_change SMALLINT NOT NULL DEFAULT 0'];
    }
}
