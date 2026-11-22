<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Infrastructure\ScopeSql;
use Nolviko\Modules\Search\Domain\ExternalSearchAdapter;
use Nolviko\Modules\Search\Domain\PostSearch;
use Nolviko\Modules\Search\Domain\SearchFilters;
use Nolviko\Modules\Search\Domain\SearchQuery;
use RuntimeException;

/** External candidates are a hint, never a visibility/count/snippet authority. Bounded incomplete count explicit. */
final class AuthorizedExternalSearch implements PostSearch
{
    public function __construct(
        private readonly Connection $db,
        private readonly ExternalSearchAdapter $external,
    ) {
    }

    /** Candidates come in the engine's own newest-first order; other orders need the native engine. */
    public function sorts(): array
    {
        return [SearchFilters::NEWEST];
    }

    public function find(
        ForumScope $scope,
        SearchQuery $query,
        ?int $before = null,
        ?SearchFilters $filters = null
    ): array {
        $filters ??= new SearchFilters();

        // External engines index post bodies; titles-only search (beta.18) always uses the native title index.
        if ($filters->titles) {
            return (new DbalPostSearch($this->db))->find($scope, $query, $before, $filters);
        }

        if ($scope->forumIds === []) {
            return ['rows' => [], 'count' => 0, 'capped' => false, 'next' => null, 'partial' => true];
        }

        $ids = $this->external->candidates($query, $filters, $before);

        if (count($ids) > 100 || array_filter($ids, static fn(mixed $id): bool => !is_int($id) || $id < 1) !== []) {
            throw new RuntimeException('search_candidates_invalid');
        }

        if ($ids === []) {
            return ['rows' => [], 'count' => 0, 'capped' => false, 'next' => null, 'partial' => true];
        }

        [$where, $parameters, $types] = ScopeSql::forums($scope, 't.forum_id');
        $where .= " AND t.status = 'visible' AND p.status = 'visible' AND t.deleted_at IS NULL "
            . 'AND p.deleted_at IS NULL AND t.moved_to_thread_id IS NULL AND p.id IN (?)';
        $parameters[] = array_values(array_unique($ids));
        $types[] = ArrayParameterType::INTEGER;

        if ($before !== null) {
            $where .= ' AND p.id < ?';
            $parameters[] = $before;
            $types[] = ParameterType::INTEGER;
        }

        $ids = ['t.forum_id' => $filters->forum, 'p.user_id' => $filters->author, 'p.thread_id' => $filters->thread];

        foreach ($ids as $column => $value) {
            if ($value !== null) {
                $where .= ' AND ' . $column . ' = ?';
                $parameters[] = $value;
                $types[] = ParameterType::INTEGER;
            }
        }

        foreach (['from' => $filters->from, 'until' => $filters->until] as $kind => $value) {
            if ($value !== null) {
                $where .= $kind === 'from' ? ' AND p.created_at >= ?' : ' AND p.created_at < ?';
                $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
                $parameters[] = ($kind === 'until' ? $date->modify('+1 day') : $date)->format('Y-m-d H:i:s');
                $types[] = ParameterType::STRING;
            }
        }

        $rows = $this->db->fetchAllAssociative('SELECT p.id, p.thread_id, t.forum_id, t.title, t.slug, p.created_at, '
            . 'LEFT(p.content, 2000) AS head, u.username AS author_name FROM posts p INNER JOIN threads t '
            . 'ON t.id = p.thread_id LEFT JOIN users u ON u.id = p.user_id WHERE ' . $where
            . ' ORDER BY p.id DESC LIMIT 21', $parameters, $types);
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);

        foreach ($rows as &$row) {
            $row['excerpt'] = (string) grapheme_substr($row['head'], 0, 240);
            $row['kind'] = 'post';
            unset($row['head']);
        }

        unset($row);

        return ['rows' => $rows, 'count' => count($rows), 'capped' => $more, 'partial' => true,
            'next' => $more ? (int) $rows[count($rows) - 1]['id'] : null];
    }
}
