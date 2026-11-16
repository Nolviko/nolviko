<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;

/**
 * Reads `audit_logs` for the admin viewer (M04.10): newest first, a keyset page below an ID (ADR-014), one
 * statement per page with the actor's name joined. An actor filter reads `ix_audit_logs_actor`, a target filter
 * `ix_audit_logs_target`; a family filter (`post`, `thread`, …) walks the primary key down and stops at the
 * page size.
 */
final class DbalAuditLog
{
    public const PAGE_SIZE = 50;

    private const FAMILY = '/^[a-z_]{1,30}$/D';

    private const TARGET_TYPE = '/^[a-z_]{1,30}$/D';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{family?: string, actor?: string, target_type?: string, target_id?: int} $filters Unknown or
     *     malformed values are ignored; an unknown actor name matches nothing.
     *
     * @return list<array{id: int, created_at: string, actor: ?string, action: string, target_type: string,
     *     target_id: int, reason: ?string, details: ?string}> Up to PAGE_SIZE + 1 rows (the extra one only tells
     *     that an older page exists).
     */
    public function page(array $filters, ?int $before = null): array
    {
        $conditions = [];
        $parameters = [];
        $types = [];

        if (isset($filters['family']) && preg_match(self::FAMILY, $filters['family']) === 1) {
            $conditions[] = 'a.action LIKE ?';
            $parameters[] = $filters['family'] . '.%';
            $types[] = ParameterType::STRING;
        }

        if (isset($filters['actor']) && $filters['actor'] !== '') {
            $actor = $this->connection->fetchOne(
                'SELECT id FROM users WHERE username_normalized = ?',
                [IdentityNormalizer::username($filters['actor'])],
            );
            $conditions[] = 'a.actor_id = ?';
            $parameters[] = $actor === false ? 0 : (int) $actor;
            $types[] = ParameterType::INTEGER;
        }

        if (isset($filters['target_type']) && preg_match(self::TARGET_TYPE, $filters['target_type']) === 1) {
            $conditions[] = 'a.target_type = ?';
            $parameters[] = $filters['target_type'];
            $types[] = ParameterType::STRING;

            if (isset($filters['target_id']) && $filters['target_id'] > 0) {
                $conditions[] = 'a.target_id = ?';
                $parameters[] = $filters['target_id'];
                $types[] = ParameterType::INTEGER;
            }
        }

        if ($before !== null) {
            $conditions[] = 'a.id < ?';
            $parameters[] = $before;
            $types[] = ParameterType::INTEGER;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.id, a.created_at, u.username AS actor, a.action, a.target_type, a.target_id, a.reason,'
                . ' a.details FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id'
                . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
                . ' ORDER BY a.id DESC LIMIT ' . (self::PAGE_SIZE + 1),
            $parameters,
            $types,
        );

        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'created_at' => (string) $row['created_at'],
            'actor' => $row['actor'] === null ? null : (string) $row['actor'],
            'action' => (string) $row['action'],
            'target_type' => (string) $row['target_type'],
            'target_id' => (int) $row['target_id'],
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
            'details' => $row['details'] === null ? null : (string) $row['details'],
        ], $rows);
    }
}
