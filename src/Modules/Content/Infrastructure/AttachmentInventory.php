<?php

declare(strict_types=1);

namespace Nolviko\Modules\Content\Infrastructure;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Forum-wide attachment inventory (beta.18 roadmap 16): post attachments newest first in keyset pages (index
 * ix_media_created from 0083), with size, type, owner and post, and a total; an administrator removes selected
 * files. Removal only marks the rows deleted; PrivateMedia::collect() deletes the files in the background, as for
 * every other removal. Avatars are not listed.
 */
final class AttachmentInventory
{
    public const PAGE = 50;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param string|null $before `created_at|id` of the last row of the previous page.
     * @return array{rows: list<array{id: string, name: string, mime: string, bytes: int, user: ?string,
     *     post_id: ?int, created_at: string}>, next: ?string, count: int, bytes: int, orphans: int}
     */
    public function page(?string $before, string $type = ''): array
    {
        [$at, $id] = is_string($before) && preg_match('/^([0-9 :.\-]{19,26})\|([a-f0-9]{32})$/D', $before, $m) === 1
            ? [$m[1], $m[2]] : [null, null];
        $where = 'm.deleted_at IS NULL AND m.avatar_for IS NULL'
            . ($type === '' ? '' : ' AND m.mime = ?')
            . ($at === null ? '' : ' AND (m.created_at < ? OR (m.created_at = ? AND m.id < ?))');
        $parameters = [...($type === '' ? [] : [$type]), ...($at === null ? [] : [$at, $at, $id])];
        $rows = $this->db->fetchAllAssociative(
            'SELECT m.id, m.original_name AS name, m.mime, m.bytes, u.username, m.post_id, '
                . 'm.created_at FROM community_media m LEFT JOIN users u ON u.id = m.user_id WHERE ' . $where
                . ' ORDER BY m.created_at DESC, m.id DESC LIMIT ' . (self::PAGE + 1),
            $parameters,
        );
        $totals = $this->db->fetchAssociative('SELECT COUNT(*) AS files, COALESCE(SUM(bytes), 0) AS bytes, '
            . 'SUM(CASE WHEN post_id IS NULL THEN 1 ELSE 0 END) AS orphans FROM community_media '
            . 'WHERE deleted_at IS NULL AND avatar_for IS NULL') ?: [];
        $more = count($rows) > self::PAGE;
        $rows = array_slice($rows, 0, self::PAGE);
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return ['rows' => array_map(static fn(array $row): array => ['id' => (string) $row['id'],
            'name' => (string) $row['name'], 'mime' => (string) $row['mime'], 'bytes' => (int) $row['bytes'],
            'user' => $row['username'] === null ? null : (string) $row['username'],
            'post_id' => $row['post_id'] === null ? null : (int) $row['post_id'],
            'created_at' => substr((string) $row['created_at'], 0, 16)], $rows),
            'next' => $more && $last !== null ? $last['created_at'] . '|' . $last['id'] : null,
            'count' => (int) ($totals['files'] ?? 0), 'bytes' => (int) ($totals['bytes'] ?? 0),
            'orphans' => (int) ($totals['orphans'] ?? 0)];
    }

    /** @return list<string> Distinct types in use, for the filter. */
    public function types(): array
    {
        return array_map('strval', $this->db->fetchFirstColumn('SELECT DISTINCT mime FROM community_media '
            . 'WHERE deleted_at IS NULL AND avatar_for IS NULL ORDER BY mime LIMIT 50'));
    }

    /**
     * @param list<string> $ids
     * @return int Rows marked deleted (the caller audits).
     */
    public function remove(array $ids): int
    {
        $ids = array_values(array_filter($ids, static fn(mixed $id): bool => is_string($id)
            && preg_match('/^[a-f0-9]{32}$/D', $id) === 1));

        if ($ids === []) {
            return 0;
        }

        $now = gmdate('Y-m-d H:i:s.000000');

        return (int) $this->db->executeStatement(
            'UPDATE community_media SET deleted_at = ? WHERE id IN (?) AND deleted_at IS NULL AND avatar_for IS NULL',
            [$now, array_slice($ids, 0, 100)],
            [1 => ArrayParameterType::STRING],
        );
    }
}
