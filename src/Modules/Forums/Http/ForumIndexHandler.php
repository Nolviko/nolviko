<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Http;

use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Settings\SiteSettings;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Forums\Domain\ForumIndex;
use Nolviko\Modules\Forums\Domain\ForumIndexEntry;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The board index (M03.09): categories and forums the reader may see, with counters and last post, rendered
 * server-side in a fixed number of queries (subject, visibility scopes, one index query). Hidden forums are
 * absent; with none visible a translated empty state is shown.
 *
 * The site setting `home.layout` (2026-11-08) can turn the home page into the latest-discussions list
 * (DiscussionFeed); the forum tree read here then feeds its forum list and labels.
 */
final class ForumIndexHandler implements RouteHandler
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly ForumVisibility $visibility,
        private readonly ForumIndex $index,
        private readonly UrlGenerator $urls,
        private readonly ?SiteSettings $settings = null,
        private readonly ?DiscussionFeed $feed = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        // The board index is the home page (2026-11-07); the old `/forums` address answers with a permanent redirect.
        if (($parameters['_route'] ?? null) === CoreRoutes::FORUM_INDEX) {
            return new Response('', Response::HTTP_MOVED_PERMANENTLY, [
                'Location' => $this->urls->generate(CoreRoutes::HOME),
            ]);
        }

        $subject = $this->subjects->of($request);
        $readable = $this->visibility->readable($subject);
        $entries = $this->index->entries($this->visibility->visible($subject), $readable);

        if ($this->feed !== null && $this->settings?->get('home.layout') === 'discussions') {
            return $this->feed->respond($request, $readable, $entries, $subject->userId);
        }

        $html = $this->views->render('forums/index.twig', [
            'title' => $this->translator->translate('forums.index.title'),
            'search_url' => $this->urls->generate(CoreRoutes::SEARCH),
            'feed_url' => $this->urls->generate(CoreRoutes::FEED_LATEST),
            'forums' => $this->rows($entries, $subject),
        ]);

        // Personal (the new-thread links and the scope depend on the reader), so never shared caches.
        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /**
     * Index rows (2026-11-09, as in vBulletin): a forum inside another forum is not a row of its own but a link
     * under its parent ("Subforums: …"); only categories and forums directly in a category or at the top level
     * are rows.
     *
     * @param list<ForumIndexEntry> $entries
     * @return list<ForumRowView>
     */
    private function rows(array $entries, SubjectPermissions $subject): array
    {
        $types = [];
        $children = [];

        foreach ($entries as $entry) {
            $types[$entry->id] = $entry->type;
        }

        foreach ($entries as $entry) {
            if ($entry->parentId !== null && ($types[$entry->parentId] ?? null) === ForumType::Forum) {
                $children[$entry->parentId][] = ['title' => $entry->title,
                    'url' => $this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (string) $entry->id])];
            }
        }

        $rows = [];

        foreach ($entries as $entry) {
            if ($entry->parentId !== null && ($types[$entry->parentId] ?? null) === ForumType::Forum) {
                continue;
            }

            $rows[] = $this->row($entry, $subject, $children[$entry->id] ?? []);
        }

        return $rows;
    }

    /** @param list<array{title: string, url: string}> $subforums */
    private function row(ForumIndexEntry $entry, SubjectPermissions $subject, array $subforums = []): ForumRowView
    {
        $forum = $entry->type === ForumType::Forum;
        $mayCreate = $forum && $subject->userId !== null && $subject->allows(CreateThread::CREATE, $entry->id);

        $link = $entry->type === ForumType::Link;

        return new ForumRowView(
            $entry->id,
            !$forum && !$link,
            $entry->title,
            $entry->depth,
            $entry->threadCount,
            $entry->postCount,
            $entry->lastPostAt,
            $entry->lastThreadTitle,
            $entry->lastPosterName,
            $mayCreate ? $this->urls->generate(CoreRoutes::THREAD_CREATE, ['forum' => (string) $entry->id]) : null,
            $forum ? $this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (string) $entry->id])
                : ($link ? $this->urls->generate(CoreRoutes::FORUM_LINK, ['forum' => (string) $entry->id]) : null),
            $entry->lastThreadId === null
                ? null
                : $this->urls->generate(
                    $entry->lastThreadSlug === null ? CoreRoutes::THREAD_ID : CoreRoutes::THREAD_SHOW,
                    ['thread' => (string) $entry->lastThreadId]
                        + ($entry->lastThreadSlug === null ? [] : ['slug' => $entry->lastThreadSlug]),
                ),
            $entry->description,
            $subforums,
            $entry->lastIsThreadStart,
        );
    }
}
