<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\SqlDialect;
use Nolviko\Modules\Threads\Domain\Drafts;

/**
 * Drafts on DBAL: an UPDATE, then INSERT IGNORE when the member had no draft for that place (portable across
 * MySQL and MariaDB). Every statement is keyed by the member, so one member never reads another's draft.
 */
final class DbalDrafts implements Drafts
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function save(int $userId, string $context, ?string $title, string $content, DateTimeImmutable $at): void
    {
        $stamp = $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $updated = $this->connection->executeStatement(
            'UPDATE drafts SET title = ?, content = ?, updated_at = ? WHERE user_id = ? AND context = ?',
            [$title, $content, $stamp, $userId, $context],
        );

        if ($updated === 0) {
            $this->connection->executeStatement(
                SqlDialect::insertIgnore(
                    $this->connection,
                    'INSERT INTO drafts (user_id, context, title, content, updated_at) VALUES (?, ?, ?, ?, ?)',
                ),
                [$userId, $context, $title, $content, $stamp],
            );
        }
    }

    public function find(int $userId, string $context): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT title, content FROM drafts WHERE user_id = ? AND context = ?',
            [$userId, $context],
        );

        return $row === false ? null : [
            'title' => $row['title'] === null ? null : (string) $row['title'],
            'content' => (string) $row['content'],
        ];
    }

    public function discard(int $userId, string $context): void
    {
        $this->connection->delete('drafts', ['user_id' => $userId, 'context' => $context]);
    }
}
