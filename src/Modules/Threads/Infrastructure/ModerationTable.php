<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0016: soft delete and the audit trail (M03.07).
 *
 * A soft delete sets status `hidden` and remembers the status it replaced, so restore returns pending content
 * to pending and visible content to visible. Because every member list filters `status = 'visible'`, nothing
 * else has to know about deletion. `audit_logs` keeps one row per moderation action; the actor is SET NULL when
 * the account goes, the target is a plain ID so the entry outlives a hard delete.
 */
final class ModerationTable implements Migration
{
    public const ID = '0016_moderation';

    private const DELETION = 'ADD COLUMN status_before_delete VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL, '
        . 'ADD COLUMN deleted_at DATETIME(6) NULL, ADD COLUMN deleted_by BIGINT NULL, ';

    public function id(): string
    {
        return self::ID;
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

        return [
            'ALTER TABLE posts ' . self::DELETION
                . 'ADD CONSTRAINT fk_posts_deleted_by FOREIGN KEY (deleted_by) REFERENCES users (id) '
                . 'ON DELETE SET NULL',
            'ALTER TABLE threads ' . self::DELETION
                . 'ADD CONSTRAINT fk_threads_deleted_by FOREIGN KEY (deleted_by) REFERENCES users (id) '
                . 'ON DELETE SET NULL',
            'CREATE TABLE audit_logs ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'actor_id BIGINT NULL, '
                . 'action VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'target_type VARCHAR(30) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'target_id BIGINT NOT NULL, '
                . 'reason VARCHAR(255) NULL, '
                . 'details TEXT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'INDEX ix_audit_logs_target (target_type, target_id, id), '
                . 'INDEX ix_audit_logs_actor (actor_id, id), '
                . 'INDEX ix_audit_logs_created (created_at), '
                . 'CONSTRAINT fk_audit_logs_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
