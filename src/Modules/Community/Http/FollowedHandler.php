<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Community\Infrastructure\CommunityActions;
use Nolviko\Modules\Community\Infrastructure\CommunityInteractions;
use Nolviko\Modules\Forums\Infrastructure\ForumSubscriptions;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Threads\Http\ThreadPageHandler;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/following` (beta.18, roadmap 18): the member's thread subscriptions and bookmarks in two lists of PER_PAGE
 * with their own "older" links, and bulk removal (POST, CSRF-checked by the kernel guard). Only readable,
 * visible threads are listed. Forum subscriptions (roadmap 01) follow, limited to forums the member may see,
 * each with a stop button that posts to the forum's subscribe route.
 */
final class FollowedHandler implements RouteHandler
{
    public const PER_PAGE = 50;

    private const KINDS = ['subscribe' => 'sub_before', 'bookmark' => 'bm_before'];

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly CommunityInteractions $interactions,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
        private readonly ?ForumSubscriptions $forums = null,
        private readonly ?ForumVisibility $visibility = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);

        if ($subject->userId === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $base = $this->urls->generate(CoreRoutes::FOLLOWED);

        try {
            if ($request->isMethod('POST')) {
                $posted = $request->request->all();
                $kind = is_string($posted['kind'] ?? null) ? $posted['kind'] : '';
                $selected = is_array($posted['threads'] ?? null) ? $posted['threads'] : [];
                $removed = isset(self::KINDS[$kind]) ? $this->interactions->unfollow($subject, $kind, array_map(
                    static fn(mixed $id): int => CommunityActions::id($id),
                    array_values($selected),
                )) : 0;

                return new Response('', Response::HTTP_SEE_OTHER, ['Location' => $base . '?removed=' . $removed,
                    'Cache-Control' => 'private, no-store']);
            }

            $lists = [];

            foreach (self::KINDS as $kind => $cursor) {
                $before = CommunityActions::id($request->query->all()[$cursor] ?? null);
                $rows = $this->interactions->followed($subject, $kind, $before, self::PER_PAGE);
                $shown = array_slice($rows, 0, self::PER_PAGE);
                $lists[$kind] = [
                    'items' => array_map(fn(array $row): array => [
                        'id' => (int) $row['id'],
                        'title' => (string) $row['title'],
                        'replies' => (int) $row['reply_count'],
                        'url' => ThreadPageHandler::url($this->urls, (int) $row['id'], 1, (string) $row['slug']),
                        'unread_url' => $this->urls->generate(
                            CoreRoutes::THREAD_UNREAD,
                            ['thread' => (string) $row['id']],
                        ),
                        'last' => $row['last_post_at'] === null ? null
                            : new DateTimeImmutable((string) $row['last_post_at'], new DateTimeZone('UTC')),
                    ], $shown),
                    'older' => count($rows) > self::PER_PAGE
                        ? $base . '?' . $cursor . '=' . (int) $shown[self::PER_PAGE - 1]['id'] : null,
                ];
            }
        } catch (RegistrationRejected) {
            return $this->errors->notFound();
        }

        $removed = $request->query->get('removed');

        return new Response($this->views->render('community/followed.twig', [
            'title' => $this->translator->translate('community.followed'),
            'subscriptions' => $lists['subscribe'],
            'bookmarks' => $lists['bookmark'],
            'forum_subscriptions' => $this->forums === null || $this->visibility === null ? []
                : array_map(fn(array $forum): array => $forum + [
                    'url' => $this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (string) $forum['id']]),
                    'unsubscribe_url' => $this->urls->generate(
                        CoreRoutes::FORUM_SUBSCRIBE,
                        ['forum' => (string) $forum['id']],
                    ),
                ], $this->forums->forums($subject->userId, $this->visibility->visible($subject)->forumIds)),
            'removed' => is_string($removed) && ctype_digit($removed) ? (int) $removed : null,
            'action' => $base,
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]), Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
