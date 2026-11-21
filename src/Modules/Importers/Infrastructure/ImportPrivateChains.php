<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Imported private conversations (beta.18): which owner's archive conversation holds the copies of one source
 * conversation, so an import keeps the conversation together instead of one archive per message.
 */
final class ImportPrivateChains implements Migration
{
    public function id(): string
    {
        return '0085_import_private_chains';
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
            'CREATE TABLE import_private_chains (job_id CHAR(32) NOT NULL, owner_id BIGINT NOT NULL, '
                . 'chain_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'conversation_id BIGINT NOT NULL, PRIMARY KEY (job_id, owner_id, chain_key), '
                . 'CONSTRAINT fk_import_chains_job FOREIGN KEY (job_id) REFERENCES import_jobs(id)) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
