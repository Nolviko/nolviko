<?php

declare(strict_types=1);

namespace Nolviko\Modules\Posts\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Infrastructure\ScopeSql;
use Nolviko\Modules\Posts\Domain\PagedPost;
use Nolviko\Modules\Posts\Domain\Post;
use Nolviko\Modules\Posts\Domain\PostPreview;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Domain\PostStatus;

/**
 * PostRepository on DBAL. Cross-thread lists join `threads` for the forum and status checks and apply the
 * scope in the WHERE clause; previews fetch only the start of the content (`LEFT()`, in characters) and cut it
 * on a grapheme boundary in PHP, so an emoji sequence is never split.
 */
final class DbalPostRepository implements PostRepository
{
    private const COLUMNS = 'id, thread_id, user_id, content, content_format, renderer_version, status, edited_at, '
        . 'edited_by, created_at, rendered_html';

    /**
     * Code points fetched for an excerpt (at most 8 KB): a grapheme may be several code points (an emoji ZWJ
     * family is five), so ten per grapheme leaves room to cut EXCERPT_LENGTH graphemes cleanly.
     */
    private const EXCERPT_FETCH = PostRepository::EXCERPT_LENGTH * 10;

    private const TIMESTAMP = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function add(
        int $threadId,
        ?int $userId,
        string $content,
        string $contentFormat,
        DateTimeImmutable $createdAt,
        PostStatus $status = PostStatus::Visible,
    ): int {
        $at = self::format($createdAt);
        $this->connection->insert('posts', [
            'thread_id' => $threadId,
            'user_id' => $userId,
            'content' => $content,
            'content_format' => $contentFormat,
            'status' => $status->value,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function find(int $id): ?Post
    {
        $row = $this->connection->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM posts WHERE id = ?', [$id]);

        return $row === false ? null : self::hydrate($row);
    }

    public function edit(
        int $postId,
        ?int $editorId,
        string $content,
        string $contentFormat,
        ?string $reason,
        DateTimeImmutable $editedAt,
    ): bool {
        return $this->connection->transactional(function (Connection $connection) use (
            $postId,
            $editorId,
            $content,
            $contentFormat,
            $reason,
            $editedAt,
        ): bool {
            $current = $connection->fetchAssociative(
                'SELECT content, content_format FROM posts WHERE id = ? FOR UPDATE',
                [$postId],
            );

            if ($current === false) {
                return false;
            }

            $at = self::format($editedAt);
            $connection->insert('post_revisions', [
                'post_id' => $postId,
                'editor_id' => $editorId,
                'previous_content' => $current['content'],
                'previous_format' => $current['content_format'],
                'change_reason' => $reason,
                'created_at' => $at,
            ]);
            $connection->update('posts', [
                'content' => $content,
                'content_format' => $contentFormat,
                'renderer_version' => null,
                'rendered_html' => null,
                'edited_at' => $at,
                'edited_by' => $editorId,
                'updated_at' => $at,
            ], ['id' => $postId]);

            return true;
        });
    }

    public function storeRendering(int $postId, string $html, int $rendererVersion, string $source): void
    {
        $this->connection->executeStatement(
            'UPDATE posts SET rendered_html = ?, renderer_version = ? WHERE id = ? AND content = ?',
            [$html, $rendererVersion, $postId, $source],
        );
    }

    public function revisions(int $postId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT editor_id, previous_content, previous_format, change_reason, created_at FROM post_revisions '
                . 'WHERE post_id = ? ORDER BY id',
            [$postId],
        );

        return array_map(static fn(array $row): array => [
            'editor_id' => $row['editor_id'] === null ? null : (int) $row['editor_id'],
            'previous_content' => (string) $row['previous_content'],
            'previous_format' => (string) $row['previous_format'],
            'change_reason' => $row['change_reason'] === null ? null : (string) $row['change_reason'],
            'created_at' => self::time((string) $row['created_at']),
        ], $rows);
    }

    public function threadPosts(int $threadId, int $limit, int $afterId = 0, bool $withDeleted = false): array
    {
        $statuses = [PostStatus::Visible->value, ...($withDeleted ? [PostStatus::Hidden->value] : [])];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM posts WHERE thread_id = ? AND status IN (?) AND id > ? ORDER BY id '
                . 'LIMIT ' . self::limit($limit),
            [$threadId, $statuses, $afterId],
            [ParameterType::INTEGER, ArrayParameterType::STRING, ParameterType::INTEGER],
        );

        return array_map(self::hydrate(...), $rows);
    }

    public function latest(ForumScope $scope, int $limit): array
    {
        [$condition, $parameters, $types] = ScopeSql::forums($scope, 't.forum_id');
        // The page is cut in a derived table that walks posts newest first, and only its rows are joined to
        // users: joined directly, MySQL chose a hash join and scanned and sorted every post (ThreadRepository
        // has the same shape).
        $rows = $this->connection->fetchAllAssociative(
            'SELECT page.id, page.thread_id, page.forum_id, page.title, page.head, author.username AS author_name, '
                . 'page.created_at FROM (SELECT p.id, p.thread_id, p.user_id, t.forum_id, t.title, '
                . 'LEFT(p.content, ' . self::EXCERPT_FETCH . ') AS head, p.created_at '
                . 'FROM posts p JOIN threads t ON t.id = p.thread_id WHERE p.status = ? AND t.status = ? AND '
                . $condition . ' ORDER BY p.id DESC LIMIT ' . self::limit($limit) . ') page '
                . 'LEFT JOIN users author ON author.id = page.user_id ORDER BY page.id DESC',
            [PostStatus::Visible->value, 'visible', ...$parameters],
            [ParameterType::STRING, ParameterType::STRING, ...$types],
        );

        return array_map(self::preview(...), $rows);
    }

    public function latestByUser(ForumScope $scope, int $userId, int $limit): array
    {
        [$condition, $parameters, $types] = ScopeSql::forums($scope, 't.forum_id');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT page.id, page.thread_id, page.forum_id, page.title, page.head, author.username AS author_name, '
                . 'page.created_at FROM (SELECT p.id, p.thread_id, p.user_id, t.forum_id, t.title, '
                . 'LEFT(p.content, ' . self::EXCERPT_FETCH . ') AS head, p.created_at '
                . 'FROM posts p JOIN threads t ON t.id = p.thread_id WHERE p.user_id = ? AND p.status = ? '
                . 'AND t.status = ? AND ' . $condition . ' ORDER BY p.created_at DESC, p.id DESC LIMIT '
                . self::limit($limit) . ') page LEFT JOIN users author ON author.id = page.user_id '
                . 'ORDER BY page.created_at DESC, page.id DESC',
            [$userId, PostStatus::Visible->value, 'visible', ...$parameters],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ...$types],
        );

        return array_map(self::preview(...), $rows);
    }

    public function threadPreviews(ForumScope $scope, array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        [$condition, $parameters, $types] = ScopeSql::forums($scope, 't.forum_id');
        $rows = $this->connection->fetchAllAssociative(
            self::previewSelect() . ' WHERE p.id = t.first_post_id AND t.id IN (?) AND p.status = ?'
                . ' AND t.status = ? AND ' . $condition,
            [$threadIds, PostStatus::Visible->value, 'visible', ...$parameters],
            [ArrayParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ...$types],
        );
        $previews = [];

        foreach ($rows as $row) {
            $previews[(int) $row['thread_id']] = self::preview($row);
        }

        return $previews;
    }

    private static function previewSelect(): string
    {
        return 'SELECT p.id, p.thread_id, t.forum_id, t.title, '
            . 'LEFT(p.content, ' . self::EXCERPT_FETCH . ') AS head, author.username AS author_name, p.created_at '
            . 'FROM posts p JOIN threads t ON t.id = p.thread_id LEFT JOIN users author ON author.id = p.user_id';
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function preview(array $row): PostPreview
    {
        // Summaries (profile, search, latest posts) show words, not markup (2026-11-14).
        $head = (string) $row['head'];
        $cut = mb_strlen($head, 'UTF-8') >= self::EXCERPT_FETCH;
        $head = self::plainText($head);
        $graphemes = (int) grapheme_strlen($head);

        // LEFT() may have cut the last grapheme in half: drop it when the fetch hit its limit.
        if ($cut) {
            --$graphemes;
        }

        $excerpt = (string) grapheme_substr($head, 0, min($graphemes, self::EXCERPT_LENGTH));

        return new PostPreview(
            (int) $row['id'],
            (int) $row['thread_id'],
            (int) $row['forum_id'],
            (string) $row['title'],
            $excerpt,
            $row['author_name'] === null ? null : (string) $row['author_name'],
            self::time((string) $row['created_at']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Post
    {
        return new Post(
            (int) $row['id'],
            (int) $row['thread_id'],
            $row['user_id'] === null ? null : (int) $row['user_id'],
            (string) $row['content'],
            (string) $row['content_format'],
            $row['renderer_version'] === null ? null : (int) $row['renderer_version'],
            PostStatus::from((string) $row['status']),
            $row['edited_at'] === null ? null : self::time((string) $row['edited_at']),
            $row['edited_by'] === null ? null : (int) $row['edited_by'],
            self::time((string) $row['created_at']),
            $row['rendered_html'] === null ? null : (string) $row['rendered_html'],
        );
    }

    public function threadPage(
        int $threadId,
        int $page,
        int $perPage,
        int $visibleTotal,
        bool $withHidden = false,
    ): array {
        $offset = ($page - 1) * $perPage;
        $count = min($perPage, $visibleTotal - $offset);

        if ($page < 1 || $perPage < 1 || $count < 1) {
            return [];
        }

        $last = $offset + $count >= $visibleTotal;
        $forward = $offset + $count / 2 <= $visibleTotal / 2;
        $select = 'SELECT p.' . str_replace(', ', ', p.', self::COLUMNS) . ', u.username AS author_name FROM posts p ';
        $authors = 'LEFT JOIN users u ON u.id = p.user_id ';
        $visible = 'SELECT id FROM posts WHERE thread_id = ? AND status = ? ORDER BY id '
            . ($forward ? 'ASC' : 'DESC') . ' LIMIT ' . $count . ' OFFSET '
            . ($forward ? $offset : $visibleTotal - $offset - $count);
        $parameters = [$threadId, PostStatus::Visible->value];

        if (!$withHidden) {
            $rows = $this->connection->fetchAllAssociative(
                $select . 'INNER JOIN (' . $visible . ') page_posts ON page_posts.id = p.id '
                    . $authors . 'ORDER BY p.id',
                $parameters,
            );
        } elseif ($offset === 0) {
            // The first page has no previous visible post. Its last visible ID bounds hidden posts until the
            // next page, and selecting both sets in the same statement keeps their view of the thread consistent.
            $rows = $this->connection->fetchAllAssociative(
                'WITH visible_page AS (' . $visible . '), page_posts AS ('
                    . 'SELECT id FROM visible_page UNION ALL '
                    . 'SELECT id FROM posts WHERE thread_id = ? AND status IN (?, ?) '
                    . 'AND EXISTS (SELECT 1 FROM visible_page)'
                    . ($last ? '' : ' AND id <= (SELECT MAX(id) FROM visible_page)') . ') '
                    . $select . 'INNER JOIN page_posts ON page_posts.id = p.id ' . $authors . 'ORDER BY p.id',
                [...$parameters, $threadId, PostStatus::Pending->value, PostStatus::Hidden->value],
            );
        } else {
            // Fetch one extra ID for the previous page's last visible post to bound the hidden posts.
            $ids = $forward
                ? $this->visibleIds($threadId, 'ASC', $count + 1, $offset - 1)
                : array_reverse($this->visibleIds($threadId, 'DESC', $count + 1, $visibleTotal - $offset - $count));
            $lower = (int) array_shift($ids);

            if ($ids === []) {
                return [];
            }

            $ids = [...$ids, ...$this->hiddenIds($threadId, $lower, $last ? null : (int) end($ids))];
            $rows = $this->connection->fetchAllAssociative(
                $select . $authors . 'WHERE p.id IN (?) ORDER BY p.id',
                [$ids],
                [ArrayParameterType::INTEGER],
            );
        }

        return array_map(
            static fn(array $row): PagedPost => new PagedPost(
                self::hydrate($row),
                $row['author_name'] === null ? null : (string) $row['author_name'],
            ),
            $rows,
        );
    }

    public function visibleBefore(Post $post, int $visibleTotal, ?int $firstPostId, ?int $lastPostId): int
    {
        $first = $firstPostId ?? $post->id;
        $last = $lastPostId ?? $post->id;
        $fromStart = $post->id - $first <= $last - $post->id;
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM posts WHERE thread_id = ? AND status = ? AND id ' . ($fromStart ? '<' : '>') . ' ?',
            [$post->threadId, PostStatus::Visible->value, $post->id],
        );

        if ($fromStart) {
            return $count;
        }

        $self = $post->status === PostStatus::Visible ? 1 : 0;

        return max(0, $visibleTotal - $count - $self);
    }

    public function lastPostAt(int $userId): ?DateTimeImmutable
    {
        // One MAX per status, so each is answered from the end of an ix_posts_user range instead of a scan
        // over all of the user's posts.
        $parts = [];
        $parameters = [];

        foreach (PostStatus::cases() as $status) {
            $parts[] = 'SELECT MAX(created_at) AS at FROM posts WHERE user_id = ? AND status = ?';
            $parameters[] = $userId;
            $parameters[] = $status->value;
        }

        $at = $this->connection->fetchOne(
            'SELECT MAX(at) FROM (' . implode(' UNION ALL ', $parts) . ') latest',
            $parameters,
        );

        return is_string($at) ? self::time($at) : null;
    }

    public function visiblePostCount(int $userId, int $cap): int
    {
        if ($cap < 1) {
            return 0;
        }

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT 1 FROM posts WHERE user_id = ? AND status = ? LIMIT ' . $cap . ') capped',
            [$userId, PostStatus::Visible->value],
        );
    }

    public function hasVisiblePosts(int $userId): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM posts WHERE user_id = ? AND status = ? LIMIT 1',
            [$userId, PostStatus::Visible->value],
        ) !== false;
    }

    /**
     * IDs only, from the covering index `ix_posts_thread (thread_id, status, id)`: the offset skips index
     * entries, not rows (deferred join; ADR-014 measured 21.6 ms against 175 ms for the last of 4,750 pages).
     *
     * @param 'ASC'|'DESC' $direction
     *
     * @return list<int>
     */
    private function visibleIds(int $threadId, string $direction, int $limit, int $offset): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM posts WHERE thread_id = ? AND status = ? ORDER BY id ' . $direction
                . ' LIMIT ' . $limit . ' OFFSET ' . max(0, $offset),
            [$threadId, PostStatus::Visible->value],
        ));
    }

    /**
     * Pending and hidden posts in (lower, upper]; unbounded above on the last page.
     *
     * @return list<int>
     */
    private function hiddenIds(int $threadId, int $lower, ?int $upper): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM posts WHERE thread_id = ? AND status IN (?) AND id > ?'
                . ($upper === null ? '' : ' AND id <= ' . $upper),
            [$threadId, [PostStatus::Pending->value, PostStatus::Hidden->value], $lower],
            [ParameterType::INTEGER, ArrayParameterType::STRING, ParameterType::INTEGER],
        ));
    }

    private static function limit(int $limit): int
    {
        return max(1, min($limit, self::MAX_LIST));
    }

    private static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::TIMESTAMP);
    }

    private static function time(string $stored): DateTimeImmutable
    {
        return new DateTimeImmutable($stored, new DateTimeZone('UTC'));
    }

    /**
     * The words of a BBCode source: images, embedded quotes, code and spoilers dropped, other tags removed with
     * their text kept, whitespace collapsed. Escaping stays the template's job.
     */
    private static function plainText(string $source): string
    {
        $text = (string) preg_replace(
            ['/\[img\b[^\]]*\].*?\[\/img\]/isu', '/\[(quote|code|spoiler)\b[^\]]*\].*?\[\/\1\]/isu'],
            ' ',
            $source,
        );
        $text = (string) preg_replace('/\[\/?[a-z*]+(?:=[^\]]*)?\]/iu', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
