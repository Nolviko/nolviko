<?php

declare(strict_types=1);

namespace Nolviko\Modules\Api\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Outgoing webhooks and their once-per-event delivery log (2026-11-08, Webhooks). */
final class WebhookTables implements Migration
{
    public function id(): string
    {
        return '0049_webhooks';
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
            'CREATE TABLE webhooks (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, url VARCHAR(500) NOT NULL, '
                . 'secret CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, events VARCHAR(200) CHARACTER SET '
                . 'ascii COLLATE ascii_bin NOT NULL, active SMALLINT NOT NULL, failures INT NOT NULL, '
                . 'last_status INT NULL, last_at DATETIME NULL, created_at DATETIME NOT NULL) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE webhook_deliveries (event_id BIGINT NOT NULL, webhook_id BIGINT NOT NULL, '
                . 'created_at DATETIME NOT NULL, PRIMARY KEY (event_id, webhook_id), '
                . 'CONSTRAINT fk_webhook_deliveries_hook FOREIGN KEY (webhook_id) REFERENCES webhooks (id) '
                . 'ON DELETE CASCADE) ENGINE=InnoDB',
        ];
    }
}
