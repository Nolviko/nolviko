<?php

declare(strict_types=1);

namespace Nolviko\Modules\Api\Http;

use Closure;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Settings\SiteSettings;
use Nolviko\Modules\Forums\Domain\ForumIndex;
use Nolviko\Modules\Forums\Domain\ForumIndexEntry;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Posts\Domain\PagedPost;
use Nolviko\Modules\Posts\Domain\PostRenderer;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Domain\PostStatus;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Threads\Domain\ThreadCursor;
use Nolviko\Modules\Threads\Domain\ThreadListItem;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only JSON API v1 (2026-11-08): forums, thread lists and threads with their posts, always as a guest sees
 * them (never with the caller's cookies), so any site or app may read it (CORS `*`) and shared caches may keep it.
 * Lists use the same keyset cursors as the pages (`?after=`); post bodies are the sanitized HTML of the thread
 * page. The site setting `api.enabled` turns it off (404).
 */
final readonly class ApiHandler implements RouteHandler
{
    public const PAGE = 25;

    /**
     * @param Closure(): SubjectPermissions $guest
     */
    public function __construct(
        private ForumIndex $index,
        private ForumVisibility $visibility,
        private Closure $guest,
        private ThreadRepository $threads,
        private PostRepository $posts,
        private PostRenderer $renderer,
        private UrlGenerator $urls,
        private SiteUrl $site,
        private SiteSettings $settings,
        private MessageTranslator $translator,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if (($this->settings->get('api.enabled') ?? 'on') === 'off') {
            return self::json(['error' => 'not_found'], 404);
        }

        $guest = ($this->guest)();
        $readable = $this->visibility->readable($guest);

        return match ($parameters['_route'] ?? '') {
            CoreRoutes::API_FORUMS => $this->forums($guest, $readable),
            CoreRoutes::API_FORUM_THREADS => $this->list($request, $readable, (int) ($parameters['forum'] ?? 0)),
            CoreRoutes::API_THREAD => $this->thread($request, $readable, (int) ($parameters['thread'] ?? 0)),
            default => $this->list($request, $readable, null),
        };
    }

    private function forums(SubjectPermissions $guest, ForumScope $readable): Response
    {
        $entries = $this->index->entries($this->visibility->visible($guest), $readable);

        return self::json(['data' => array_map(fn(ForumIndexEntry $entry): array => [
            'id' => $entry->id,
            'parent_id' => $entry->parentId,
            'type' => $entry->type->value,
            'title' => $entry->title,
            'description' => $entry->description,
            'depth' => $entry->depth,
            'threads' => $entry->threadCount,
            'posts' => $entry->postCount,
            'last_post_at' => $entry->lastPostAt?->format(DATE_ATOM),
            'url' => $this->absolute(CoreRoutes::FORUM_SHOW, ['forum' => (string) $entry->id]),
        ], $entries)]);
    }

    private function list(Request $request, ForumScope $readable, ?int $forum): Response
    {
        $token = $request->query->get('after');
        $after = is_string($token) ? ThreadCursor::decode($token) : null;

        if (($token !== null && $after === null) || ($forum !== null && !$readable->contains($forum))) {
            return self::json(['error' => 'not_found'], 404);
        }

        $items = $this->threads->latest($forum === null ? $readable : new ForumScope([$forum]), self::PAGE + 1, $after);
        $more = count($items) > self::PAGE;
        $items = array_slice($items, 0, self::PAGE);
        $last = $items === [] ? null : ThreadCursor::of($items[count($items) - 1]);

        return self::json([
            'data' => array_map(fn(ThreadListItem $item): array => [
                'id' => $item->id,
                'forum_id' => $item->forumId,
                'title' => $item->title,
                'author' => $item->authorName,
                'last_poster' => $item->lastPosterName,
                'last_post_at' => $item->lastPostAt?->format(DATE_ATOM),
                'replies' => $item->replyCount,
                'views' => $item->viewCount,
                'locked' => $item->locked,
                'url' => $this->absolute(
                    CoreRoutes::THREAD_SHOW,
                    ['slug' => $item->slug, 'thread' => (string) $item->id],
                ),
            ], $items),
            'next' => $more && $last !== null ? $last->encode() : null,
        ]);
    }

    private function thread(Request $request, ForumScope $readable, int $threadId): Response
    {
        $thread = $this->threads->find($threadId);

        if (
            $thread === null || $thread->status !== ThreadStatus::Visible || $thread->movedToThreadId !== null
            || !$readable->contains($thread->forumId)
        ) {
            return self::json(['error' => 'not_found'], 404);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $spoiler = $this->translator->translate('core.post.spoiler');
        $rows = $this->posts->threadPage($thread->id, $page, 20, $thread->replyCount + 1);

        return self::json([
            'data' => [
                'id' => $thread->id,
                'forum_id' => $thread->forumId,
                'title' => $thread->title,
                'replies' => $thread->replyCount,
                'locked' => $thread->isLocked,
                'url' => $this->absolute(
                    CoreRoutes::THREAD_SHOW,
                    ['slug' => $thread->slug, 'thread' => (string) $thread->id],
                ),
                'page' => $page,
                'pages' => max(1, (int) ceil(($thread->replyCount + 1) / 20)),
                'posts' => array_values(array_map(fn(PagedPost $row): array => [
                    'id' => $row->post->id,
                    'author' => $row->authorName,
                    'created_at' => $row->post->createdAt->format(DATE_ATOM),
                    'edited_at' => $row->post->editedAt?->format(DATE_ATOM),
                    'html' => (string) $this->renderer->html($row->post, $spoiler),
                ], array_filter($rows, static fn(PagedPost $row): bool => $row->post->status === PostStatus::Visible))),
            ],
        ]);
    }

    /** @param array<string, string> $parameters */
    private function absolute(string $route, array $parameters): string
    {
        $path = $this->urls->generate($route, $parameters);

        return $this->site->absolute($path) ?? $path;
    }

    /** @param array<string, mixed> $body */
    private static function json(array $body, int $status = 200): Response
    {
        $response = new JsonResponse($body, $status, [
            'Cache-Control' => $status === 200 ? 'public, max-age=60' : 'public, max-age=30',
            'Access-Control-Allow-Origin' => '*',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
        $response->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $response;
    }
}
