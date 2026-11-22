<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Community\Infrastructure\CommunityRead;
use Nolviko\Modules\Community\Infrastructure\MemberStanding;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Moderation\Domain\ReportPost;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Posts\Domain\PagedPost;
use Nolviko\Modules\Posts\Domain\PostRenderer;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Threads\Domain\CreateReply;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\EditPost;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ReadTracker;
use Nolviko\Modules\Threads\Domain\Thread;
use Nolviko\Modules\Threads\Domain\ThreadCommands;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Threads\Infrastructure\ThreadTaxonomy;
use Nolviko\Modules\Threads\Infrastructure\ThreadViews;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A numbered thread page (M03.10, ADR-014). `/threads/{thread}` is page 1, `/threads/{thread}/page-{page}` the
 * others. `page-1` redirects (301) to the canonical first-page URL, a redirect stub to the moved thread; a page
 * past the last one, an unreadable thread or one the reader may not see is a plain 404. Post bodies come from
 * the render cache; members never see pending, hidden or soft-deleted posts, moderators see them marked.
 */
final class ThreadPageHandler implements RouteHandler
{
    public const DEFAULT_PER_PAGE = 20;

    /** Page links shown on each side of the current page. */
    private const WINDOW = 2;

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly ThreadRepository $threads,
        private readonly PostRepository $posts,
        private readonly PostRenderer $renderer,
        private readonly ForumVisibility $visibility,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
        private readonly int $perPage = self::DEFAULT_PER_PAGE,
        private readonly ?ReadTracker $reads = null,
        private readonly ?CommunityRead $community = null,
        private readonly ?ForumRepository $forums = null,
        private readonly ?CsrfTokens $csrf = null,
        private readonly ?ThreadViews $viewCounter = null,
        private readonly ?ThreadTaxonomy $taxonomy = null,
        private readonly ?MemberStanding $standing = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $threadId = (int) ($parameters['thread'] ?? 0);
        $page = (int) ($parameters['page'] ?? 1);
        $subject = $this->subjects->of($request);
        $thread = $this->threads->find($threadId);

        $readable = $this->visibility->readable($subject);

        if ($thread === null || !$readable->contains($thread->forumId)) {
            // ADR-041: a thread of a forum behind a password the visitor has not entered leads to its form.
            if ($thread !== null && $subject->lockingForum($thread->forumId) !== null) {
                return new Response('', Response::HTTP_SEE_OTHER, ['Location' => $this->urls->generate(
                    CoreRoutes::FORUM_UNLOCK,
                    ['forum' => (string) $thread->forumId],
                ), 'Cache-Control' => 'private, no-store']);
            }

            return $this->errors->notFound();
        }

        if ($thread->movedToThreadId !== null) {
            // Straight to the target's canonical address, but its slug (part of a title) only for readers who
            // may read it; others get the id form, whose page answers 404 for them.
            $target = $this->threads->find($thread->movedToThreadId);
            $slug = $target !== null && $target->status === ThreadStatus::Visible
                && $readable->contains($target->forumId) ? $target->slug : null;

            return self::permanent(self::url($this->urls, $thread->movedToThreadId, 1, $slug));
        }

        $moderator = ModerateContent::seesDeleted($subject, $thread->forumId);

        if ($thread->status !== ThreadStatus::Visible && !$moderator) {
            return $this->errors->notFound();
        }

        // One address per page (2026-11-14): the id-only form, a changed title's old slug and `page-1` all answer
        // 301 with `/threads/{slug}-{id}[/page-N]`, the query string kept. Only after the read and status checks,
        // so a hidden thread's title never leaks through a redirect. Since 2026-11-15 an extension may move thread
        // addresses to a pattern without the slug: then the thread route itself matches without one, and that is
        // the canonical form (no redirect loop).
        $slugless = in_array($parameters['_route'] ?? null, [CoreRoutes::THREAD_SHOW, CoreRoutes::THREAD_PAGE], true)
            && !array_key_exists('slug', $parameters);
        if (
            (!$slugless && ($parameters['slug'] ?? null) !== $thread->slug)
            || (isset($parameters['page']) && $page === 1)
        ) {
            $query = (string) $request->getQueryString();

            return self::permanent($this->pageUrl($thread, $page) . ($query === '' ? '' : '?' . $query));
        }

        $visible = self::visibleTotal($thread);
        $pages = self::pageCount($visible, $this->perPage);

        if ($page > $pages) {
            return $this->errors->notFound();
        }

        $spoiler = $this->translator->translate('core.post.spoiler');
        $paged = $this->posts->threadPage($thread->id, $page, $this->perPage, $visible, $moderator);
        $mayReport = $subject->userId !== null && $subject->allows(ReportPost::PERMISSION, $thread->forumId);
        // Community additions first: they decide (privacy, blocks) whether the avatar and profile link may show.
        $extras = $this->community?->extras($subject, array_map(
            static fn(PagedPost $item): int => $item->post->id,
            $paged,
        )) ?? [];
        $ranks = $this->standing?->standing(array_values(array_filter(array_map(
            static fn(PagedPost $item): ?int => $item->post->userId,
            $paged,
        )))) ?? [];
        // Edit link (2026-11-09): the rules of EditPost::authorize from the permissions already loaded, no query.
        $editAny = $subject->allows(EditPost::EDIT_ANY, $thread->forumId);
        $editOwn = $subject->userId !== null && $subject->allows(EditPost::EDIT_OWN, $thread->forumId)
            && !$thread->isLocked && $thread->status === ThreadStatus::Visible;
        $window = (int) $subject->value(EditPost::WINDOW, $thread->forumId);
        $now = time();
        $editable = static fn(PagedPost $item): bool => $editAny || ($editOwn
            && $item->post->userId === $subject->userId && $item->post->status === PostStatus::Visible
            && ($window <= 0 || $now < $item->post->createdAt->getTimestamp() + $window * 60));
        $avatars = [];
        $profiles = [];
        $profile = $this->urls->generate(CoreRoutes::COMMUNITY, ['section' => 'profile']);

        foreach ($extras as $postId => $extra) {
            if (!empty($extra['avatar'])) {
                $avatars[$postId] = $this->urls->generate(
                    CoreRoutes::COMMUNITY_MEDIA,
                    ['media' => (string) $extra['avatar']],
                );
            }
            if (!empty($extra['profile_id'])) {
                $profiles[$postId] = $profile . '?user=' . (int) $extra['profile_id'];
            }
        }

        $replyUrl = $this->mayReply($subject, $thread)
            ? $this->urls->generate(CoreRoutes::THREAD_REPLY, ['thread' => (string) $thread->id])
            : null;
        $posts = array_map(
            fn(PagedPost $paged, int $index): PostView => new PostView(
                $paged->post->id,
                $paged->authorName,
                $paged->post->createdAt,
                $paged->post->editedAt,
                $this->renderer->html($paged->post, $spoiler),
                $paged->post->status === PostStatus::Visible ? null : $paged->post->status->value,
                $this->urls->generate(CoreRoutes::POST_SHOW, ['post' => (string) $paged->post->id]),
                $mayReport && $paged->post->status === PostStatus::Visible && $paged->post->userId !== $subject->userId
                    ? $this->urls->generate(CoreRoutes::POST_REPORT, ['post' => (string) $paged->post->id])
                    : null,
                $avatars[$paged->post->id] ?? null,
                $profiles[$paged->post->id] ?? null,
                $replyUrl !== null && $paged->post->status === PostStatus::Visible
                    ? $replyUrl . '?quote=' . $paged->post->id
                    : null,
                $paged->post->editedAt !== null && ($moderator
                    || ($subject->userId !== null && $paged->post->userId === $subject->userId))
                    ? $this->urls->generate(CoreRoutes::POST_HISTORY, ['post' => (string) $paged->post->id])
                    : null,
                $ranks[$paged->post->userId ?? 0]['rank'] ?? null,
                $editable($paged)
                    ? $this->urls->generate(CoreRoutes::POST_EDIT, ['post' => (string) $paged->post->id]) : null,
                // Place in the thread (2026-11-14), not the site-wide id.
                ($page - 1) * $this->perPage + $index + 1,
            ),
            $paged,
            array_keys($paged),
        );

        // M03.12: a member has read the thread up to the newest post shown here (never further, never back).
        if ($this->reads !== null && $subject->userId !== null && $paged !== []) {
            $newest = $paged[0]->post;

            foreach ($paged as $item) {
                $newest = $item->post->createdAt > $newest->createdAt ? $item->post : $newest;
            }

            $this->reads->markThread($subject->userId, $thread->id, $newest->id, $newest->createdAt);
        }
        // Inline thread tools (bookmark, subscribe, poll, solution; 2026-11-07). Not for pending/hidden threads, which
        // the community layer refuses. The CSRF token only goes to members, so guest pages stay render-cacheable.
        $tools = null;

        if ($this->community !== null) {
            try {
                $tools = $this->community->threadTools($subject, $thread->id);
            } catch (RegistrationRejected) {
                $tools = null;
            }
        }

        $forms = $subject->userId !== null && $this->csrf !== null
            ? ['csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token()]
            : [];
        $forms['moderation'] = $forms === [] ? null : $this->moderationTools($subject, $thread);
        $this->viewCounter?->record(
            (string) $request->headers->get('User-Agent', ''),
            (string) ($request->headers->get('Sec-Purpose') ?? $request->headers->get('Purpose') ?? ''),
            $thread->id,
            $subject->userId,
        );
        // beta.18 roadmap 34: `?print=1` renders the same page without forms or navigation.
        $print = $request->query->get('print') === '1';
        $html = $this->views->render($print ? 'threads/print.twig' : 'threads/show.twig', $forms + [
            'tools' => $tools,
            'thread_id' => $thread->id,
            'community_url' => $this->community === null ? null
                : $this->urls->generate(CoreRoutes::COMMUNITY, ['section' => 'thread']) . '?thread=' . $thread->id,
            'community_extras' => $extras,
            'title' => $thread->title,
            'trail' => $this->trail($thread->forumId, $readable),
            'canonical' => $this->pageUrl($thread, $page),
            'page' => $page,
            'pages' => $pages,
            'thread_status' => $thread->status === ThreadStatus::Visible ? null : $thread->status->value,
            'locked' => $thread->isLocked,
            'posts' => $posts,
            'links' => $this->links($thread, $page, $pages),
            'previous' => $page > 1 ? $this->pageUrl($thread, $page - 1) : null,
            'next' => $page < $pages ? $this->pageUrl($thread, $page + 1) : null,
            'reply_url' => $replyUrl,
            'reply_total' => $thread->replyCount,
            'forum_home_url' => $this->urls->generate(CoreRoutes::HOME),
            'labels' => $this->taxonomy === null ? null : array_merge(
                $this->taxonomy->forThreads([$thread->id])[$thread->id] ?? ['prefix' => null, 'tags' => []],
                ['manage_url' => $subject->allows(ModerateContent::MODERATE, $thread->forumId)
                    ? $this->urls->generate(CoreRoutes::THREAD_LABELS, ['thread' => (string) $thread->id]) : null],
            ),
        ]);

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-cache',
        ] + ($print ? ['X-Robots-Tag' => 'noindex'] : []));
    }

    /**
     * Pin/lock buttons and the manage link for moderators of this forum (2026-11-17); null when they may do none.
     *
     * @return array{action: string, manage_url: string, sticky: string|null, lock: string|null}|null
     */
    private function moderationTools(SubjectPermissions $subject, Thread $thread): ?array
    {
        $may = static fn(string $command): bool => $subject->allows(
            (string) ThreadCommands::permissionFor($command),
            $thread->forumId,
        );
        $sticky = $may(ThreadCommands::STICKY) ? ($thread->isSticky ? ThreadCommands::UNSTICKY : ThreadCommands::STICKY)
            : null;
        $lock = $may(ThreadCommands::LOCK) ? ($thread->isLocked ? ThreadCommands::UNLOCK : ThreadCommands::LOCK) : null;

        if ($sticky === null && $lock === null && !$may(ThreadCommands::MOVE)) {
            return null;
        }

        $manage = $this->urls->generate(CoreRoutes::THREAD_MANAGE, ['thread' => (string) $thread->id]);

        return ['action' => $manage, 'manage_url' => $manage, 'sticky' => $sticky, 'lock' => $lock];
    }

    /**
     * Breadcrumb from the root down to the thread's forum: one primary-key lookup per level (bounded by the tree
     * depth). An ancestor the reader may not see ends the trail there, so no hidden forum name leaks; categories
     * have no page and are shown without a link.
     *
     * @return list<array{title: string, url: string|null}>
     */
    private function trail(int $forumId, ForumScope $readable): array
    {
        $trail = [];
        $id = $forumId;

        for ($level = 0; $this->forums !== null && $id !== null && $level < 16; $level++) {
            $forum = $this->forums->find($id);

            if ($forum === null || !$readable->contains($forum->id)) {
                break;
            }

            array_unshift($trail, ['title' => $forum->title, 'url' => $forum->type === ForumType::Forum
                ? $this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (string) $forum->id]) : null]);
            $id = $forum->parentId;
        }

        return $trail;
    }

    /**
     * Visible posts of the thread from its stored counter: a pending thread's first post is pending too,
     * otherwise the first post is always visible. Shared with PostPermalinkHandler.
     */
    public static function visibleTotal(Thread $thread): int
    {
        return $thread->replyCount + ($thread->status === ThreadStatus::Pending ? 0 : 1);
    }

    public static function pageCount(int $visibleTotal, int $perPage): int
    {
        return max(1, intdiv($visibleTotal + $perPage - 1, $perPage));
    }

    /**
     * The URL of a thread page: page 1 has no page segment. With the slug it is the canonical
     * `/threads/{slug}-{id}`; without (moved-thread targets, redirects after a form) the `/threads/{id}` form,
     * which answers 301 with the canonical one.
     */
    public static function url(UrlGenerator $urls, int $threadId, int $page, ?string $slug = null): string
    {
        $parameters = ['thread' => (string) $threadId] + ($slug === null ? [] : ['slug' => $slug]);

        if ($page !== 1) {
            $parameters['page'] = (string) $page;
        }

        return $urls->generate(match (true) {
            $slug === null => $page === 1 ? CoreRoutes::THREAD_ID : CoreRoutes::THREAD_ID_PAGE,
            default => $page === 1 ? CoreRoutes::THREAD_SHOW : CoreRoutes::THREAD_PAGE,
        }, $parameters);
    }

    private function pageUrl(Thread $thread, int $page): string
    {
        return self::url($this->urls, $thread->id, $page, $thread->slug);
    }

    /**
     * First and last page, the current one with WINDOW pages on each side, and gaps (number 0) in between.
     *
     * @return list<PageLinkView>
     */
    private function links(Thread $thread, int $page, int $pages): array
    {
        if ($pages === 1) {
            return [];
        }

        $numbers = array_unique([1, ...range(max(1, $page - self::WINDOW), min($pages, $page + self::WINDOW)), $pages]);
        sort($numbers);
        $links = [];
        $previous = 0;

        foreach ($numbers as $number) {
            if ($number > $previous + 1) {
                $links[] = new PageLinkView(0, null, false);
            }

            $links[] = new PageLinkView($number, $this->pageUrl($thread, $number), $number === $page);
            $previous = $number;
        }

        return $links;
    }

    /**
     * The same rules as CreateReply::authorize() without its lookups: shows the link only, the reply form checks
     * again.
     */
    private function mayReply(SubjectPermissions $subject, Thread $thread): bool
    {
        return $subject->userId !== null
            && $thread->status === ThreadStatus::Visible
            && $subject->allows(CreateReply::REPLY, $thread->forumId)
            && (!$thread->isLocked || $subject->allows(CreateThread::LOCK_BYPASS, $thread->forumId));
    }

    private static function permanent(string $location): Response
    {
        return new Response('', Response::HTTP_MOVED_PERMANENTLY, ['Location' => $location]);
    }
}
