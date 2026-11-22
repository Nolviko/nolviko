<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Http;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\RateLimit\ClientNetwork;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Http\ForumOptions;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Search\Domain\PostSearch;
use Nolviko\Modules\Search\Domain\SearchFilters;
use Nolviko\Modules\Search\Domain\SearchQuery;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Threads\Http\ThreadPageHandler;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Public read-only search uses the current reader's SQL scope; private no-store results, no shared result cache. */
final class SearchHandler implements RouteHandler
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly ForumVisibility $visibility,
        private readonly PostSearch $search,
        private readonly RateBuckets $buckets,
        private readonly UrlGenerator $urls,
        private readonly ?ForumRepository $forums = null,
        private readonly ?UserRepository $users = null,
        private readonly int $intervalSeconds = 1,
        private readonly ?ThreadRepository $threads = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $input = $request->query->all();
        $text = is_string($input['q'] ?? null) ? $input['q'] : '';
        $beforeText = is_string($input['before'] ?? null) ? $input['before'] : '';
        $query = SearchQuery::parse($text);
        $before = $beforeText === '' ? null : filter_var(
            $beforeText,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 999999999999999999]]
        );
        $filters = new SearchFilters();
        $invalidFilters = false;
        // The form asks for a username (2026-11-07); `author` (a member number) still works for old links.
        $authorName = is_string($input['author_name'] ?? null) ? trim($input['author_name']) : '';
        $unknownAuthor = false;

        if ($authorName !== '' && ($input['author'] ?? '') === '' && $this->users !== null) {
            try {
                $author = mb_strlen($authorName) <= 64 ? $this->users->findByUsername($authorName) : null;
            } catch (Throwable) {
                $author = null;
            }
            $unknownAuthor = $author === null;
            $input['author'] = $author === null ? '' : (string) $author->id;
        }

        try {
            $filters = SearchFilters::parse($input);

            // An order this engine cannot give falls back to newest first (beta.18, roadmap 26).
            if (!in_array($filters->sort, $this->search->sorts(), true)) {
                $filters = new SearchFilters(
                    $filters->forum,
                    $filters->author,
                    $filters->from,
                    $filters->until,
                    $filters->thread,
                    $filters->titles
                );
            }
        } catch (InvalidArgumentException) {
            $invalidFilters = true;
        }

        $rows = [];
        $partial = false;
        $count = 0;
        $capped = false;
        $next = null;
        $error = null;
        $status = 200;

        if (isset($input['q']) && ($query === null || $before === false || $invalidFilters)) {
            $error = $this->translator->translate('search.invalid');
            $status = 422;
        } elseif ($unknownAuthor && $query !== null) {
            $error = $this->translator->translate('search.author_unknown');
            $status = 422;
        } elseif ($query !== null) {
            try {
                $network = ClientNetwork::key($request->getClientIp()) ?? 'unknown';
                $key = 'search.net:' . hash('sha256', $network);
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $wait = 0;
                if ($this->intervalSeconds > 0) {
                    $this->buckets->ensure($key, $now);
                    $wait = $this->buckets->hit($key, $this->intervalSeconds, $now);
                }

                if ($wait > 0) {
                    $error = $this->translator->translate('search.wait');
                    $status = 429;
                } else {
                    $scope = $this->visibility->readable($this->subjects->of($request));
                    $result = $this->search->find($scope, $query, $before, $filters);
                    $count = $result['count'];
                    $capped = $result['capped'];
                    $partial = $result['partial'] ?? false;

                    foreach ($result['rows'] as $row) {
                        $rows[] = [
                            'title' => $row['title'],
                            'excerpt' => $row['excerpt'],
                            'author' => $row['author_name'],
                            'date' => new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')),
                            // A thread result opens the thread; a post result goes through the permalink, which
                            // answers with the post's page and anchor.
                            'url' => ($row['kind'] ?? 'post') === 'thread'
                                ? ThreadPageHandler::url($this->urls, (int) $row['id'], 1, $row['slug'] ?? null)
                                : $this->urls->generate(CoreRoutes::POST_SHOW, ['post' => (string) $row['id']]),
                        ];
                    }

                    if ($result['next'] !== null) {
                        $next = $this->urls->generate(CoreRoutes::SEARCH) . '?' . http_build_query(
                            ['q' => $query->text, 'before' => $result['next']] + $filters->query(),
                            encoding_type: PHP_QUERY_RFC3986,
                        );
                    }
                }
            } catch (Throwable) {
                $error = $this->translator->translate('search.unavailable');
                $status = 503;
            }
        }

        $shownAuthor = $authorName;
        $forumOptions = [];
        $inThread = null;

        // Form helpers only: without them the page falls back to the plain number field and still works.
        try {
            if ($shownAuthor === '' && isset($filters->query()['author']) && $this->users !== null) {
                $shownAuthor = $this->users->findById((int) $filters->query()['author'])?->username ?? '';
            }

            // Search inside a thread (beta.18, roadmap 09): its title and address only when the reader may see it.
            $scoped = $filters->thread === null ? null : $this->threads?->find($filters->thread);

            if (
                $scoped !== null && $scoped->status === ThreadStatus::Visible && $scoped->movedToThreadId === null
                && $this->visibility->readable($this->subjects->of($request))->contains($scoped->forumId)
            ) {
                $inThread = ['id' => $scoped->id, 'title' => $scoped->title,
                    'url' => ThreadPageHandler::url($this->urls, $scoped->id, 1, $scoped->slug)];
            }

            if ($this->forums !== null) {
                $forumOptions = ForumOptions::readable(
                    $this->forums,
                    $this->visibility->readable($this->subjects->of($request)),
                );
            }
        } catch (Throwable) {
            $forumOptions = [];
        }

        $response = new Response($this->views->render('search/index.twig', [
            'forum_options' => $forumOptions,
            'in_thread' => $inThread,
            'sorts' => $this->search->sorts(),
            'author_name' => $shownAuthor,
            'title' => $this->translator->translate('search.title'),
            'action' => $this->urls->generate(CoreRoutes::SEARCH),
            'index_url' => $this->urls->generate(CoreRoutes::HOME),
            'query' => $query?->text ?? '',
            'filters' => $filters->query(),
            'searched' => $query !== null && $error === null,
            'error' => $error,
            'rows' => $rows,
            'count' => $count,
            'capped' => $capped,
            'partial' => $partial,
            'next' => $next,
        ]), $status, ['Cache-Control' => 'private, no-store', 'Content-Type' => 'text/html; charset=UTF-8',
            'Referrer-Policy' => 'same-origin', 'X-Robots-Tag' => 'noindex, nofollow']);

        if ($status === 429) {
            $response->headers->set('Retry-After', '1');
        }

        return $response;
    }
}
