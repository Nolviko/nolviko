<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Http;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Community\Infrastructure\CommunityRead;
use Nolviko\Modules\Forums\Domain\ForumIndexEntry;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Threads\Domain\ReadTracker;
use Nolviko\Modules\Threads\Domain\ThreadCursor;
use Nolviko\Modules\Threads\Domain\ThreadListItem;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Infrastructure\ThreadTaxonomy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The home page in the discussions layout (site setting `home.layout`, 2026-11-08): the latest visible threads of
 * every readable forum, newest activity first, with their forum as a label and the forum tree beside them. "Show
 * more" is a keyset link (`?after=`, ADR-014), so it works without JavaScript; a malformed cursor is a 404 and
 * cursor pages are `noindex`. Members see unread markers (ReadTracker, two queries). No votes or scores (owner
 * decision). One thread query on top of the board index's.
 */
final readonly class DiscussionFeed
{
    public const PER_PAGE = 25;

    public function __construct(
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private ThreadRepository $threads,
        private UrlGenerator $urls,
        private ErrorResponder $errors,
        private ?ReadTracker $reads = null,
        private ?Closure $clock = null,
        private ?ThreadTaxonomy $taxonomy = null,
        private ?CommunityRead $community = null,
    ) {
    }

    /**
     * @param list<ForumIndexEntry> $forums The reader's board index (visible forums in tree order).
     */
    /**
     * @param array{id: int, name: string, slug: string}|null $tag A tag page (TagPageHandler) instead of the home page.
     */
    public function respond(
        Request $request,
        ForumScope $readable,
        array $forums,
        ?int $userId = null,
        ?array $tag = null,
    ): Response {
        $token = $request->query->get('after');
        $after = is_string($token) ? ThreadCursor::decode($token) : null;

        if ($token !== null && $after === null) {
            return $this->errors->notFound();
        }

        $items = $this->threads->latest($readable, self::PER_PAGE + 1, $after, $tag['id'] ?? null);
        $more = count($items) > self::PER_PAGE;
        $items = array_slice($items, 0, self::PER_PAGE);
        $last = $items === [] ? null : ThreadCursor::of($items[count($items) - 1]);
        $unread = [];

        if ($this->reads !== null && $userId !== null && $items !== []) {
            $threads = [];

            foreach ($items as $item) {
                $threads[$item->id] = [$item->forumId, $item->lastPostAt];
            }

            $now = $this->clock !== null ? ($this->clock)()
                : new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $unread = array_fill_keys($this->reads->unreadAcross($userId, $threads, $now), true);
        }
        $home = $tag === null ? $this->urls->generate(CoreRoutes::HOME)
            : $this->urls->generate(CoreRoutes::TAG_SHOW, ['tag' => $tag['slug']]);
        $avatars = $this->community?->avatars(
            array_values(array_filter(array_map(static fn(ThreadListItem $item): ?int => $item->lastPosterId, $items))),
            $userId !== null,
        ) ?? [];
        $labels = $this->taxonomy?->forThreads(
            array_map(static fn(ThreadListItem $item): int => $item->id, $items),
        ) ?? [];
        $labels = [];
        $sidebar = [];

        foreach ($forums as $entry) {
            $url = $entry->type === ForumType::Forum
                ? $this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (string) $entry->id]) : null;
            $labels[$entry->id] = [$entry->title, $url];
            $sidebar[] = ['title' => $entry->title, 'url' => $url, 'sub' => $entry->depth > 0 && $url !== null];
        }

        $html = $this->views->render('forums/discussions.twig', [
            'title' => $tag === null ? $this->translator->translate('forums.feed.title')
                : $this->translator->translate('forums.feed.tag_title', ['tag' => $tag['name']]),
            'tag_list' => array_map(fn(array $one): array => ['name' => $one['name'],
                'url' => $this->urls->generate(CoreRoutes::TAG_SHOW, ['tag' => $one['slug']]),
                'current' => $one['slug'] === ($tag['slug'] ?? null)], $this->taxonomy?->tags() ?? []),
            'indexable' => $after === null,
            'meta_description' => $tag === null ? null
                : $this->translator->translate('forums.feed.tag_description', ['tag' => $tag['name']]),
            'feed_url' => $this->urls->generate(CoreRoutes::FEED_LATEST),
            'discussions' => array_map(
                fn(ThreadListItem $item): DiscussionRowView => new DiscussionRowView(
                    $item->title,
                    $this->urls->generate(
                        CoreRoutes::THREAD_SHOW,
                        ['slug' => $item->slug, 'thread' => (string) $item->id],
                    ),
                    $item->locked,
                    $item->lastPostAt,
                    $item->authorName,
                    $item->lastPosterName,
                    $item->replyCount,
                    $labels[$item->forumId][0] ?? null,
                    $labels[$item->forumId][1] ?? null,
                    isset($unread[$item->id]),
                    $item->viewCount,
                    $labels[$item->id]['prefix'] ?? null,
                    array_map(
                        fn(array $one): array => ['name' => $one['name'],
                        'url' => $this->urls->generate(CoreRoutes::TAG_SHOW, ['tag' => $one['slug']])],
                        $labels[$item->id]['tags'] ?? []
                    ),
                    isset($avatars[$item->lastPosterId ?? 0]) ? $this->urls->generate(
                        CoreRoutes::COMMUNITY_MEDIA,
                        ['media' => $avatars[$item->lastPosterId ?? 0]],
                    ) : null,
                ),
                $items,
            ),
            'more' => $more && $last !== null ? $home . '?after=' . $last->encode() : null,
            'forums' => $sidebar,
        ]);

        // Personal (the scope depends on the reader), so never shared caches.
        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-cache',
        ]);
    }
}
