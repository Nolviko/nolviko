<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Http;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/members/suggest?q=&thread=` (2026-11-08, participants first 2026-11-09): up to LIMIT active member names
 * for the @mention, message-recipient and search-author fields (autocomplete.js). With a thread the reader may
 * read, the people who posted in it come first (most recent first) and are offered as soon as `@` is typed;
 * other members follow once q has two characters. Signed-in members only; names only (no IDs or emails).
 */
final readonly class MemberSuggestHandler implements RouteHandler
{
    public const LIMIT = 8;

    public function __construct(
        private Connection $db,
        private RequestSubject $subjects,
        private ?ForumVisibility $visibility = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $headers = ['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex'];

        if ($this->subjects->userId($request) === null) {
            return new JsonResponse([], Response::HTTP_FORBIDDEN, $headers);
        }

        $query = $request->query->get('q', '');
        $query = is_string($query) && mb_check_encoding($query, 'UTF-8') && mb_strlen($query) <= 32 ? trim($query) : '';
        $prefix = $query === '' ? '' : IdentityNormalizer::username($query);
        $names = $this->participants($request, $prefix);

        if (mb_strlen($query) >= 2 && count($names) < self::LIMIT) {
            $names = array_merge($names, $this->db->fetchFirstColumn(
                "SELECT username FROM users WHERE username_normalized LIKE ? AND status = 'active' "
                    . 'ORDER BY username_normalized LIMIT ' . self::LIMIT,
                [addcslashes($prefix, '%_\\') . '%'],
            ));
        }

        $unique = [];

        foreach ($names as $name) {
            $unique[mb_strtolower((string) $name)] ??= (string) $name;
        }

        return new JsonResponse(array_slice(array_values($unique), 0, self::LIMIT), Response::HTTP_OK, $headers);
    }

    /** @return list<string> */
    private function participants(Request $request, string $prefix): array
    {
        $thread = $request->query->getInt('thread');

        if ($thread < 1 || $this->visibility === null) {
            return [];
        }

        $forum = $this->db->fetchOne("SELECT forum_id FROM threads WHERE id = ? AND status = 'visible'", [$thread]);

        if ($forum === false || !$this->visibility->readable($this->subjects->of($request))->contains((int) $forum)) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT u.username, u.username_normalized FROM posts p INNER JOIN users u ON u.id = p.user_id '
                . "WHERE p.thread_id = ? AND p.status = 'visible' AND u.status = 'active' "
                . 'GROUP BY u.id, u.username, u.username_normalized ORDER BY MAX(p.id) DESC LIMIT 100',
            [$thread],
        );
        $names = [];

        foreach ($rows as $row) {
            if ($prefix === '' || str_starts_with((string) $row['username_normalized'], $prefix)) {
                $names[] = (string) $row['username'];
            }
        }

        return array_slice($names, 0, self::LIMIT);
    }
}
