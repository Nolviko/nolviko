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

/**
 * Authorization is part of both SQL selections before count/page cuts; snippets use only authorized rows.
 *
 * beta.18: posts of one thread (roadmap 09), thread titles with one row per thread (roadmap 11, index 0065) and
 * three orders (roadmap 26). Newest and oldest are keyset pages on the ID; relevance orders the newest
 * RELEVANCE_WINDOW matches by the engine's score and pages by offset inside that window, so a common word never
 * sorts the whole board.
 */
final class DbalPostSearch implements PostSearch
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function sorts(): array
    {
        return SearchFilters::SORTS;
    }

    /** @return array{rows: list<array<string, mixed>>, count: int, capped: bool, next: int|null} */
    public function find(
        ForumScope $scope,
        SearchQuery $query,
        ?int $before = null,
        ?SearchFilters $filters = null
    ): array {
        $filters ??= new SearchFilters();

        if ($scope->forumIds === [] || ($filters->forum !== null && !$scope->contains($filters->forum))) {
            return ['rows' => [], 'count' => 0, 'capped' => false, 'next' => null];
        }

        $postgres = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
        $titles = $filters->titles;
        $relevance = $filters->sort === SearchFilters::RELEVANCE;
        $oldest = $filters->sort === SearchFilters::OLDEST;
        // In titles mode the row is the thread: its starter is the author and its start the date.
        $alias = $titles ? 't' : 'p';
        [$scopeSql, $parameters, $types] = $filters->forum === null
            ? ScopeSql::forums($scope, 't.forum_id')
            : ['t.forum_id = ?', [$filters->forum], [ParameterType::INTEGER]];
        $where = "t.status = 'visible' AND t.moved_to_thread_id IS NULL AND " . $scopeSql . ' AND '
            . SearchDialect::match($postgres, $titles ? 't.title' : 'p.content') . ' AND t.deleted_at IS NULL'
            . ($titles ? '' : " AND p.status = 'visible' AND p.deleted_at IS NULL");
        $value = SearchDialect::value($query, $postgres);
        $parameters[] = $value;
        $types[] = ParameterType::STRING;
        $ids = [$alias . '.user_id' => $filters->author, 'p.thread_id' => $titles ? null : $filters->thread];

        foreach ($ids as $column => $id) {
            if ($id !== null) {
                $where .= ' AND ' . $column . ' = ?';
                $parameters[] = $id;
                $types[] = ParameterType::INTEGER;
            }
        }

        foreach (['from' => $filters->from, 'until' => $filters->until] as $kind => $day) {
            if ($day !== null) {
                $date = new DateTimeImmutable($day, new DateTimeZone('UTC'));
                $where .= ' AND ' . $alias . '.created_at ' . ($kind === 'from' ? '>=' : '<') . ' ?';
                $parameters[] = ($kind === 'until' ? $date->modify('+1 day') : $date)->format('Y-m-d H:i:s');
                $types[] = ParameterType::STRING;
            }
        }

        $from = $titles ? 'threads t' : 'posts p INNER JOIN threads t ON t.id = p.thread_id';
        $matchWhere = $where;
        $matchParameters = $parameters;
        $matchTypes = $types;
        $offset = $relevance ? min(max(0, $before ?? 0), self::RELEVANCE_WINDOW) : 0;

        if (!$relevance && $before !== null) {
            $where .= ' AND ' . $alias . '.id ' . ($oldest ? '>' : '<') . ' ?';
            $parameters[] = $before;
            $types[] = ParameterType::INTEGER;
        }

        $columns = $titles
            ? 't.id, t.id AS thread_id, t.forum_id, t.title, t.slug, t.user_id, t.created_at, t.first_post_id'
            : 'p.id, p.thread_id, t.forum_id, t.title, t.slug, p.user_id, p.created_at, p.id AS first_post_id';

        if ($relevance) {
            // The score is bound with the same value as the match; the window keeps the sort bounded.
            // MySQL answers the score from the full-text index, so the window never reads post bodies.
            $inner = 'SELECT * FROM (SELECT ' . $columns . ', '
                . SearchDialect::score($postgres, $titles ? 't.title' : 'p.content') . ' AS score FROM ' . $from
                . ' WHERE ' . $where . ' ORDER BY ' . $alias . '.id DESC LIMIT ' . self::RELEVANCE_WINDOW
                . ') window_rows ORDER BY score DESC, id DESC LIMIT 21 OFFSET ' . $offset;
            $parameters = [$value, ...$parameters];
            $types = [ParameterType::STRING, ...$types];
            $outer = 'page.score DESC, page.id DESC';
        } else {
            $direction = $oldest ? 'ASC' : 'DESC';
            $inner = 'SELECT ' . $columns . ' FROM ' . $from . ' WHERE ' . $where
                . ' ORDER BY ' . $alias . '.id ' . $direction . ' LIMIT 21';
            $outer = 'page.id ' . $direction;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT page.id, page.thread_id, page.forum_id, page.title, page.slug, page.created_at, '
                . 'LEFT(body.content, 2000) AS head, author.username AS author_name FROM (' . $inner . ') page '
                . 'LEFT JOIN posts body ON body.id = page.first_post_id '
                . 'LEFT JOIN users author ON author.id = page.user_id ORDER BY ' . $outer,
            $parameters,
            $types,
        );
        $more = count($rows) > 20;
        $count = count($rows);

        // A complete first page already contains every match. Later pages still need the global count.
        if ($more || $before !== null) {
            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM (SELECT ' . $alias . '.id FROM ' . $from . ' WHERE ' . $matchWhere
                    . ' LIMIT 1001) authorized_matches',
                $matchParameters,
                $matchTypes,
            );
        }

        $rows = array_slice($rows, 0, 20);

        foreach ($rows as &$row) {
            $head = (string) $row['head'];
            $length = (int) grapheme_strlen($head);

            if (mb_strlen($head, 'UTF-8') >= 2000) {
                $length = max(0, $length - 1);
            }

            // Plain escaped source preview. Never render BBCode/HTML or highlight with untrusted markup.
            $row['excerpt'] = (string) grapheme_substr($head, 0, min(240, $length));
            $row['kind'] = $titles ? 'thread' : 'post';
            unset($row['head']);
        }

        unset($row);
        $next = null;

        if ($more) {
            $next = $relevance
                ? ($offset + 20 < self::RELEVANCE_WINDOW ? $offset + 20 : null)
                : (int) $rows[count($rows) - 1]['id'];
        }

        return ['rows' => $rows, 'count' => min(1000, $count), 'capped' => $count > 1000, 'next' => $next];
    }
}
