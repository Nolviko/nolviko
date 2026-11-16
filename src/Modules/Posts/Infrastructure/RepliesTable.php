<?php

declare(strict_types=1);

namespace Nolviko\Modules\Posts\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * 0015: `threads.reply_count` and `idempotency_keys` (M03.05).
 *
 * `reply_count` counts visible posts after the first one and is kept under the thread row lock, so thread lists
 * never count posts. `idempotency_keys` holds one row per (user, scope, form token): a double submit of the same
 * form finds the row and returns the first result instead of writing again. Old keys are pruned by a task
 * (M08); the created_at index serves it.
 */
final class RepliesTable implements Migration
{
    public const ID = '0015_thread_replies';

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
            'ALTER TABLE threads ADD COLUMN reply_count BIGINT NOT NULL DEFAULT 0 AFTER is_sticky',
            "UPDATE threads SET reply_count = (SELECT COUNT(*) FROM posts p WHERE p.thread_id = threads.id "
                . "AND p.status = 'visible' AND p.id <> COALESCE(threads.first_post_id, 0))",
            'CREATE TABLE idempotency_keys ('
                . 'id BIGINT NOT NULL AUTO_INCREMENT, '
                . 'user_id BIGINT NOT NULL, '
                . 'scope VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'token VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'result_id BIGINT NULL, '
                . 'created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (id), '
                . 'CONSTRAINT uq_idempotency_keys UNIQUE (user_id, scope, token), '
                . 'INDEX ix_idempotency_keys_created (created_at), '
                . 'CONSTRAINT fk_idempotency_keys_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
        ];
    }
}
