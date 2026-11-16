<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration\Core;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Additive durable extension migration checkpoints and transactional subscriber receipts. */
final class ExtensionContracts implements Migration
{
    public function id(): string
    {
        return '0039_extension_contracts';
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
            'CREATE TABLE extension_schema ('
                . 'extension_id VARCHAR(65) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'migration_id VARCHAR(69) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'status VARCHAR(16) NOT NULL, PRIMARY KEY (extension_id, migration_id)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE extension_event_receipts ('
                . 'extension_id VARCHAR(65) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
                . 'event_id BIGINT NOT NULL, PRIMARY KEY (extension_id, event_id)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
