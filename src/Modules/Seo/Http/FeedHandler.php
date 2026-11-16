<?php

declare(strict_types=1);

namespace Nolviko\Modules\Seo\Http;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Threads\Domain\ThreadListItem;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Atom feeds (2026-11-08): `/feed.xml` (latest threads of the whole board) and `/forums/{forum}/feed.xml` (one
 * forum). Like the sitemaps they are read as a guest, never with the caller's account, so a shared cache may
 * keep them; absolute links need the configured site address (404 without it). Each entry is a thread with a
 * plain-text summary of its first post (BBCode tags removed, escaped, never rendered). Three queries.
 */
final readonly class FeedHandler implements RouteHandler
{
    public const ENTRIES = 20;

    private const SUMMARY = 300;

    /**
     * @param Closure(): SubjectPermissions $guest
     */
    public function __construct(
        private Connection $db,
        private ThreadRepository $threads,
        private ForumRepository $forums,
        private ForumVisibility $visibility,
        private Closure $guest,
        private SiteUrl $site,
        private UrlGenerator $urls,
        private MessageTranslator $translator,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $home = $this->site->absolute($this->urls->generate(CoreRoutes::HOME));

        if ($home === null) {
            return self::missing();
        }

        $readable = $this->visibility->readable(($this->guest)());
        $title = $this->translator->translate('seo.feed.latest');
        $self = CoreRoutes::FEED_LATEST;
        $selfParameters = [];
        $page = $home;

        if (($parameters['_route'] ?? null) === CoreRoutes::FEED_FORUM) {
            $forumId = (int) ($parameters['forum'] ?? 0);
            $forum = $this->forums->find($forumId);

            if ($forum === null || $forum->type !== ForumType::Forum || !$readable->contains($forumId)) {
                return self::missing();
            }

            $readable = new ForumScope([$forumId]);
            $title = $forum->title;
            $self = CoreRoutes::FEED_FORUM;
            $selfParameters = ['forum' => (string) $forumId];
            $page = (string) $this->site->absolute(
                $this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (string) $forumId]),
            );
        }

        $items = $this->threads->latest($readable, self::ENTRIES);
        $summaries = $this->summaries(array_map(static fn(ThreadListItem $item): int => $item->id, $items));
        $selfUrl = (string) $this->site->absolute($this->urls->generate($self, $selfParameters));
        $updated = $items === [] || $items[0]->lastPostAt === null
            ? new DateTimeImmutable('@0') : $items[0]->lastPostAt;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom">'
            . '<title>' . self::escape($title) . '</title>'
            . '<id>' . self::escape($selfUrl) . '</id>'
            . '<link rel="self" type="application/atom+xml" href="' . self::escape($selfUrl) . '"/>'
            . '<link rel="alternate" type="text/html" href="' . self::escape($page) . '"/>'
            . '<updated>' . self::time($updated) . '</updated>';

        foreach ($items as $item) {
            $url = (string) $this->site->absolute(
                $this->urls->generate(CoreRoutes::THREAD_SHOW, ['slug' => $item->slug, 'thread' => (string) $item->id]),
            );
            $author = $item->authorName ?? $this->translator->translate('threads.show.removed_account');
            $xml .= '<entry><title>' . self::escape($item->title) . '</title>'
                . '<id>' . self::escape($url) . '</id>'
                . '<link rel="alternate" type="text/html" href="' . self::escape($url) . '"/>'
                . '<updated>' . self::time($item->lastPostAt ?? $updated) . '</updated>'
                . '<author><name>' . self::escape($author) . '</name></author>'
                . (isset($summaries[$item->id])
                    ? '<summary type="text">' . self::escape($summaries[$item->id]) . '</summary>' : '')
                . '</entry>';
        }

        return new Response($xml . '</feed>', Response::HTTP_OK, [
            'Content-Type' => 'application/atom+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Plain-text start of each thread's first visible post.
     *
     * @param list<int> $threadIds
     * @return array<int, string>
     */
    private function summaries(array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        $rows = $this->db->fetchAllKeyValue(
            "SELECT t.id, LEFT(p.content, 2000) FROM threads t INNER JOIN posts p ON p.id = t.first_post_id "
                . "WHERE t.id IN (?) AND p.status = 'visible'",
            [$threadIds],
            [ArrayParameterType::INTEGER],
        );
        $summaries = [];

        foreach ($rows as $id => $content) {
            $text = trim((string) preg_replace(
                ['~\[/?[a-z*]+(?:=[^\]]*)?\]~i', '/\s+/u'],
                ['', ' '],
                (string) $content,
            ));
            $short = (string) grapheme_substr($text, 0, self::SUMMARY);
            $summaries[(int) $id] = $short === $text ? $text : rtrim($short) . '…';
        }

        return $summaries;
    }

    private static function escape(string $value): string
    {
        // Characters XML 1.0 forbids are dropped rather than escaped.
        $clean = (string) preg_replace(
            '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            '',
            $value,
        );

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function time(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function missing(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'private, no-store']);
    }
}
