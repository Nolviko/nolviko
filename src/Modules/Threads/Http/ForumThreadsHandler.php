<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Community\Infrastructure\CommunityRead;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\ForumSubscriptions;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ReadTracker;
use Nolviko\Modules\Threads\Domain\ThreadCursor;
use Nolviko\Modules\Threads\Domain\ThreadListItem;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Threads\Infrastructure\ThreadTaxonomy;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A forum page (M03.11, ADR-014): its threads, sticky first then by last activity, in keyset pages of
 * PER_PAGE. `?after=` and `?before=` carry an opaque ThreadCursor; one row more than the page is read to know
 * whether another page exists. A malformed cursor, both cursors at once, an unreadable forum or a category is a
 * plain 404. Cursor pages are `noindex`; the first page is canonical.
 */
final class ForumThreadsHandler implements RouteHandler
{
    public const PER_PAGE = 25;

    /** Member-chosen orders (roadmap 14); empty means the default last-activity order. */
    private const SORTS = ['title', 'replies', 'started'];

    private const MAX_SORTED_PAGES = 100;

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly ForumRepository $forums,
        private readonly ThreadRepository $threads,
        private readonly ForumVisibility $visibility,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
        private readonly ?ReadTracker $reads = null,
        private readonly ?CsrfTokens $csrf = null,
        private readonly ?ThreadTaxonomy $taxonomy = null,
        private readonly ?CommunityRead $community = null,
        private readonly ?ForumSubscriptions $subscriptions = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $forumId = (int) ($parameters['forum'] ?? 0);
        $afterToken = $request->query->get('after');
        $beforeToken = $request->query->get('before');
        $after = is_string($afterToken) ? ThreadCursor::decode($afterToken) : null;
        $before = is_string($beforeToken) ? ThreadCursor::decode($beforeToken) : null;

        if (
            ($afterToken !== null && $after === null)
            || ($beforeToken !== null && $before === null)
            || ($after !== null && $before !== null)
        ) {
            return $this->errors->notFound();
        }

        $subject = $this->subjects->of($request);
        $forum = $this->forums->find($forumId);
        $scope = $this->visibility->readable($subject);

        if ($forum !== null && $forum->type === ForumType::Link) {
            return new Response('', Response::HTTP_SEE_OTHER, ['Location' => $this->urls->generate(
                CoreRoutes::FORUM_LINK,
                ['forum' => (string) $forumId],
            )]);
        }

        if ($forum === null || $forum->type !== ForumType::Forum || !$scope->contains($forumId)) {
            // ADR-041: a visible forum behind a password the visitor has not entered shows its password form.
            if ($forum !== null && $subject->lockingForum($forumId) !== null) {
                return new Response('', Response::HTTP_SEE_OTHER, ['Location' => $this->urls->generate(
                    CoreRoutes::FORUM_UNLOCK,
                    ['forum' => (string) $forumId],
                ), 'Cache-Control' => 'private, no-store']);
            }

            return $this->errors->notFound();
        }

        $moderator = ModerateContent::seesDeleted($subject, $forumId);
        $base = $this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (string) $forumId]);
        // Roadmap 14 (beta.18): a member-chosen order uses numbered pages, at most MAX_SORTED_PAGES of them; the
        // default last-activity order keeps keyset pagination (ADR-014).
        $sort = $request->query->get('sort');
        $sort = is_string($sort) && in_array($sort, self::SORTS, true) ? $sort : null;
        $pageNumber = max(1, min(self::MAX_SORTED_PAGES, (int) $request->query->get('page', '1')));

        if ($sort !== null && ($after !== null || $before !== null)) {
            return $this->errors->notFound();
        }

        if ($sort !== null) {
            $items = $this->threads->forumThreadsSorted(
                $scope,
                $forumId,
                $sort,
                self::PER_PAGE + 1,
                ($pageNumber - 1) * self::PER_PAGE,
                $moderator,
            );
            $more = count($items) > self::PER_PAGE && $pageNumber < self::MAX_SORTED_PAGES;
            $items = array_slice($items, 0, self::PER_PAGE);
        } else {
            $items = $this->threads->forumThreads($scope, $forumId, self::PER_PAGE + 1, $moderator, $after, $before);
            $more = count($items) > self::PER_PAGE;
            // The extra row lies beyond the page: after the last row going forwards, before the first going back.
            $items = $before === null ? array_slice($items, 0, self::PER_PAGE) : array_slice($items, -self::PER_PAGE);
        }

        $first = $items === [] ? null : ThreadCursor::of($items[0]);
        $last = $items === [] ? null : ThreadCursor::of($items[count($items) - 1]);
        $hasOlder = $before !== null || $more;
        $hasNewer = $before !== null ? $more : $after !== null;
        $sortBase = $sort === null ? null : $base . '?sort=' . $sort . '&page=';
        $newerUrl = $sortBase !== null ? ($pageNumber > 1 ? $sortBase . ($pageNumber - 1) : null)
            : ($hasNewer && $first !== null ? $base . '?before=' . $first->encode() : null);
        $olderUrl = $sortBase !== null ? ($more ? $sortBase . ($pageNumber + 1) : null)
            : ($hasOlder && $last !== null ? $base . '?after=' . $last->encode() : null);
        $unread = [];

        if ($this->reads !== null && $subject->userId !== null && $items !== []) {
            $lastPosts = [];

            foreach ($items as $item) {
                $lastPosts[$item->id] = $item->movedToThreadId === null ? $item->lastPostAt : null;
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $unread = array_fill_keys($this->reads->unread($subject->userId, $forumId, $lastPosts, $now), true);
        }
        // One read of the forum tree gives the parent breadcrumb and, on the first page, the readable subforums.
        $tree = [];

        foreach ($this->forums->all() as $node) {
            $tree[$node->id] = $node;
        }

        $trail = [];

        for ($id = $forum->parentId, $level = 0; $id !== null && isset($tree[$id]) && $level < 16; $level++) {
            if (!$scope->contains($id)) {
                $trail = [];
                break;
            }

            array_unshift($trail, ['title' => $tree[$id]->title, 'url' => $tree[$id]->type === ForumType::Forum
                ? $this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (string) $id]) : null]);
            $id = $tree[$id]->parentId;
        }

        $subforums = [];

        if ($after === null && $before === null && $pageNumber === 1) {
            foreach ($tree as $node) {
                if ($node->parentId === $forumId && $node->type === ForumType::Forum && $scope->contains($node->id)) {
                    $subforums[] = ['title' => $node->title,
                        'url' => $this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (string) $node->id])];
                }
            }
        }

        $avatars = $this->community?->avatars(
            array_values(array_filter(array_map(static fn(ThreadListItem $item): ?int => $item->lastPosterId, $items))),
            $subject->userId !== null,
        ) ?? [];
        $labels = $this->taxonomy?->forThreads(
            array_map(static fn(ThreadListItem $item): int => $item->id, $items),
        ) ?? [];
        $html = $this->views->render('forums/show.twig', [
            'trail' => $trail,
            'subforums' => $subforums,
            'title' => $forum->title,
            'description' => $forum->description,
            'canonical' => $base,
            'feed_url' => $this->urls->generate(CoreRoutes::FEED_FORUM, ['forum' => (string) $forumId]),
            'indexable' => $after === null && $before === null && $sort === null,
            'sort' => $sort,
            'sorts' => self::SORTS,
            'threads' => array_map(
                fn(ThreadListItem $item): ThreadRowView => $this->row(
                    $item,
                    isset($unread[$item->id]),
                    $labels[$item->id]['prefix'] ?? null,
                    isset($avatars[$item->lastPosterId ?? 0]) ? $this->urls->generate(
                        CoreRoutes::COMMUNITY_MEDIA,
                        ['media' => $avatars[$item->lastPosterId ?? 0]],
                    ) : null,
                ),
                $items,
            ),
            'mark_read_url' => $this->reads !== null && $this->csrf !== null && $subject->userId !== null
                ? $this->urls->generate(CoreRoutes::FORUM_READ, ['forum' => (string) $forumId])
                : null,
            // beta.18 roadmap 01: subscribe to new threads of this forum.
            'subscribe_url' => $this->subscriptions !== null && $this->csrf !== null && $subject->userId !== null
                ? $this->urls->generate(CoreRoutes::FORUM_SUBSCRIBE, ['forum' => (string) $forumId]) : null,
            'subscribed' => $subject->userId !== null && $this->subscriptions?->has($subject->userId, $forumId),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf !== null && $subject->userId !== null ? $this->csrf->token() : '',
            'newer' => $newerUrl,
            'older' => $olderUrl,
            'index_url' => $this->urls->generate(CoreRoutes::HOME),
            'new_thread_url' => $subject->userId !== null && $subject->allows(CreateThread::CREATE, $forumId)
                ? $this->urls->generate(CoreRoutes::THREAD_CREATE, ['forum' => (string) $forumId])
                : null,
        ]);

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /** @param array{id: int, label: string, class: string}|null $prefix */
    private function row(
        ThreadListItem $item,
        bool $unread,
        ?array $prefix = null,
        ?string $avatar = null,
    ): ThreadRowView {
        return new ThreadRowView(
            $item->title,
            ThreadPageHandler::url(
                $this->urls,
                $item->movedToThreadId ?? $item->id,
                1,
                $item->movedToThreadId === null ? $item->slug : null,
            ),
            $item->sticky,
            $item->locked,
            $item->movedToThreadId !== null,
            $item->lastPostAt,
            $item->authorName,
            $item->lastPosterName,
            $item->status === ThreadStatus::Visible ? null : $item->status->value,
            $unread,
            $item->movedToThreadId === null ? $item->replyCount : null,
            $item->movedToThreadId === null ? $item->viewCount : null,
            $prefix,
            $avatar,
            // beta.18 (roadmap 13): the "new" marker leads to the first unread post.
            $unread && $item->movedToThreadId === null
                ? $this->urls->generate(CoreRoutes::THREAD_UNREAD, ['thread' => (string) $item->id]) : null,
        );
    }
}
