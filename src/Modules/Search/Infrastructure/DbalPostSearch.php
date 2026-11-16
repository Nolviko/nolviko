<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Infrastructure\ScopeSql;
use Nolviko\Modules\Search\Domain\PostSearch;
use Nolviko\Modules\Search\Domain\SearchFilters;
use Nolviko\Modules\Search\Domain\SearchQuery;

/** Authorization is part of both SQL selections before count/page cuts; snippets use only authorized rows. */
final class DbalPostSearch implements PostSearch
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array{rows: list<array<string, mixed>>, count: int, capped: bool, next: int|null} */
    public function find(
        ForumScope $scope,
        SearchQuery $query,
        ?int $before = null,
        ?SearchFilters $filters = null
    ): array {
        if ($scope->forumIds === []) {
            return ['rows' => [], 'count' => 0, 'capped' => false, 'next' => null];
        }

        $filters ??= new SearchFilters();
        $postgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        [$scopeSql, $parameters, $types] = ScopeSql::forums($scope, 't.forum_id');
        $where = "t.status = 'visible' AND p.status = 'visible' AND t.moved_to_thread_id IS NULL AND "
            . $scopeSql . ' AND ' . SearchDialect::predicate($postgres)
            . ' AND t.deleted_at IS NULL AND p.deleted_at IS NULL';
        $parameters[] = SearchDialect::value($query, $postgres);
        $types[] = ParameterType::STRING;
        foreach (['t.forum_id' => $filters->forum, 'p.user_id' => $filters->author] as $column => $value) {
            if ($value !== null) {
                $where .= ' AND ' . $column . ' = ?';
                $parameters[] = $value;
                $types[] = ParameterType::INTEGER;
            }
        }

        foreach (['from' => $filters->from, 'until' => $filters->until] as $kind => $value) {
            if ($value !== null) {
                $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
                $where .= $kind === 'from' ? ' AND p.created_at >= ?' : ' AND p.created_at < ?';
                $parameters[] = ($kind === 'until' ? $date->modify('+1 day') : $date)->format('Y-m-d H:i:s');
                $types[] = ParameterType::STRING;
            }
        }

        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT p.id FROM posts p INNER JOIN threads t ON t.id = p.thread_id '
                . 'WHERE ' . $where . ' LIMIT 1001) authorized_matches',
            $parameters,
            $types,
        );

        if ($before !== null) {
            $where .= ' AND p.id < ?';
            $parameters[] = $before;
            $types[] = ParameterType::INTEGER;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT page.id, page.thread_id, page.forum_id, page.title, page.head, page.created_at, '
                . 'author.username AS author_name FROM ('
                . 'SELECT p.id, p.thread_id, t.forum_id, t.title, p.user_id, '
                . 'LEFT(p.content, 2000) AS head, p.created_at '
                . 'FROM posts p INNER JOIN threads t ON t.id = p.thread_id WHERE ' . $where
                . ' ORDER BY p.id DESC LIMIT 21) page LEFT JOIN users author ON author.id = page.user_id '
                . 'ORDER BY page.id DESC',
            $parameters,
            $types,
        );
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);

        foreach ($rows as &$row) {
            $head = (string) $row['head'];
            $length = (int) grapheme_strlen($head);

            if (mb_strlen($head, 'UTF-8') >= 2000) {
                $length = max(0, $length - 1);
            }

            // Plain escaped source preview. Never render BBCode/HTML or highlight with untrusted markup.
            $row['excerpt'] = (string) grapheme_substr($head, 0, min(240, $length));
            unset($row['head']);
        }

        unset($row);

        return ['rows' => $rows, 'count' => min(1000, $count), 'capped' => $count > 1000,
            'next' => $more ? (int) $rows[count($rows) - 1]['id'] : null];
    }
}
