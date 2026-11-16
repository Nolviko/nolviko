<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Owner decision 2026-11-14: accepting an answer belongs to question-and-answer forums only. A forum is one when
 * an administrator says so (`is_questions`, off for every existing and new forum).
 */
final class ForumQuestionMode implements Migration
{
    public function id(): string
    {
        return '0051_forum_question_mode';
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

        return ['ALTER TABLE forums ADD COLUMN is_questions TINYINT(1) NOT NULL DEFAULT 0'];
    }
}
