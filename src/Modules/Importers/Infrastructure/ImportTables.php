<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Additive durable import checkpoints and immutable ID/content mappings; no source credentials or payload logs. */
final class ImportTables implements Migration
{
    public function id(): string
    {
        return '0042_import_jobs';
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
        $options = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        return [
            'ALTER TABLE forums ADD COLUMN description MEDIUMTEXT NULL',
            'ALTER TABLE member_profiles ADD COLUMN imported_signature MEDIUMTEXT NULL',
            'ALTER TABLE installed_release ADD COLUMN release_version VARCHAR(128) NULL',
            'ALTER TABLE private_conversations ADD COLUMN imported_subject VARCHAR(255) NULL',
            'ALTER TABLE private_conversations ADD COLUMN import_job_id CHAR(32) NULL',
            'ALTER TABLE private_messages ADD COLUMN imported_content MEDIUMTEXT NULL',
            'ALTER TABLE poll_options ADD COLUMN imported_label VARCHAR(255) NULL',
            'ALTER TABLE poll_options ADD COLUMN imported_votes BIGINT NOT NULL DEFAULT 0',
            'ALTER TABLE thread_polls ADD COLUMN imported_question VARCHAR(255) NULL',
            'ALTER TABLE thread_polls ADD COLUMN imported_lock SMALLINT NOT NULL DEFAULT 0',
            'ALTER TABLE thread_polls ADD COLUMN imported_results SMALLINT NOT NULL DEFAULT 0',
            'ALTER TABLE thread_polls ADD COLUMN imported_max_choices SMALLINT NULL',
            'ALTER TABLE community_media ADD COLUMN imported_name VARCHAR(255) NULL',
            'ALTER TABLE community_media ADD COLUMN imported_retained SMALLINT NOT NULL DEFAULT 0',
            'ALTER TABLE community_media ADD COLUMN imported_visibility VARCHAR(16) NULL',
            'CREATE TABLE import_jobs (id CHAR(32) NOT NULL PRIMARY KEY, source_key CHAR(64) NOT NULL UNIQUE, '
                . 'actor_id BIGINT NOT NULL, engine VARCHAR(12) NOT NULL, source_version VARCHAR(32) NOT NULL, '
                . "phase SMALLINT NOT NULL DEFAULT 0, cursor_id VARCHAR(80) NOT NULL DEFAULT '', "
                . "state VARCHAR(16) NOT NULL DEFAULT 'running', revision BIGINT NOT NULL DEFAULT 0, "
                . 'processed BIGINT NOT NULL DEFAULT 0, verified BIGINT NOT NULL DEFAULT 0, '
                . 'report_json TEXT NOT NULL, diagnostic VARCHAR(80) NULL, '
                . 'created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, '
                . 'INDEX ix_import_jobs_actor (actor_id, created_at, id), INDEX '
                    . 'ix_import_jobs_state (state, id))' . $options,
            'CREATE TABLE import_map (job_id CHAR(32) NOT NULL, entity VARCHAR(16) NOT NULL, '
                . 'source_id VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'target_id VARCHAR(64) NOT NULL, source_hash CHAR(64) NOT NULL, '
                . 'content_hash CHAR(64) NULL, meta_json TEXT NOT NULL, reason '
                    . 'VARCHAR(80) NULL, source_parent BIGINT NULL, '
                . 'PRIMARY KEY (job_id, entity, source_id), INDEX ix_import_map_target (entity, target_id), '
                . 'INDEX ix_import_map_parent (job_id, entity, source_parent, source_id), '
                . 'INDEX ix_import_map_reason (job_id, reason, entity, source_id), '
                . 'CONSTRAINT fk_import_map_job FOREIGN KEY (job_id) REFERENCES import_jobs(id))' . $options,
            'CREATE TABLE legacy_redirects (job_id CHAR(32) NOT NULL, entity VARCHAR(16) NOT NULL, '
                . 'source_id BIGINT NOT NULL, target_id VARCHAR(64) NOT NULL, '
                . 'PRIMARY KEY (job_id, entity, source_id), CONSTRAINT fk_legacy_redirect_job '
                . 'FOREIGN KEY (job_id) REFERENCES import_jobs(id))' . $options,
            'CREATE TABLE import_private_files (id CHAR(32) NOT NULL PRIMARY KEY, job_id CHAR(32) NOT NULL, '
                . 'source_id BIGINT NOT NULL, owner_id BIGINT NULL, conversation_id BIGINT NULL, '
                . 'file_key CHAR(64) NOT NULL, original_name VARCHAR(255) NOT NULL, bytes BIGINT NOT NULL, '
                . 'source_hash CHAR(64) NOT NULL, INDEX ix_import_private_owner (owner_id, id), '
                . 'INDEX ix_import_private_source (job_id, source_id, owner_id), '
                . 'INDEX ix_import_private_refs (file_key, owner_id, conversation_id), '
                . 'CONSTRAINT fk_import_private_job FOREIGN KEY (job_id) REFERENCES import_jobs(id), '
                . 'CONSTRAINT fk_import_private_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL, '
                . 'CONSTRAINT fk_import_private_conversation FOREIGN KEY (conversation_id) '
                . 'REFERENCES private_conversations(id) ON DELETE SET NULL)' . $options,

        ];
    }
}
