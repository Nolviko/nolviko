<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\Drafts;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Domain\ThreadStatus;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/drafts` (beta.18, roadmap 17): the member's unsent thread and reply drafts from every forum and thread, newest
 * first, with a link that opens the same form again (where the draft is loaded) and deletion of one or several.
 * A draft whose place the member can no longer read or post in stays listed without its place's name or link, so
 * it can still be deleted. POST is CSRF-checked by the kernel guard; only the member's own rows are touched.
 */
final class DraftsHandler implements RouteHandler
{
    public const LIMIT = 100;

    private const CONTEXT = '/^(thread|reply):([1-9][0-9]{0,17})$/D';

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly Drafts $drafts,
        private readonly ForumRepository $forums,
        private readonly ThreadRepository $threads,
        private readonly ForumVisibility $visibility,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);

        if ($subject->userId === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        if ($request->isMethod('POST')) {
            $selected = $request->request->all()['drafts'] ?? [];
            $deleted = 0;

            foreach (is_array($selected) ? array_slice($selected, 0, self::LIMIT) : [] as $context) {
                if (is_string($context) && preg_match(self::CONTEXT, $context) === 1) {
                    $this->drafts->discard($subject->userId, $context);
                    $deleted++;
                }
            }

            return new Response('', Response::HTTP_SEE_OTHER, [
                'Location' => $this->urls->generate(CoreRoutes::DRAFTS) . '?deleted=' . $deleted,
                'Cache-Control' => 'private, no-store',
            ]);
        }

        $scope = $this->visibility->readable($subject);
        $items = [];

        foreach ($this->drafts->forMember($subject->userId, self::LIMIT) as $draft) {
            if (preg_match(self::CONTEXT, $draft['context'], $match) !== 1) {
                continue;
            }

            $place = null;
            $url = null;

            if ($match[1] === 'thread') {
                $forum = $this->forums->find((int) $match[2]);

                if (
                    $forum !== null && $forum->type === ForumType::Forum && $scope->contains($forum->id)
                    && $subject->allows(CreateThread::CREATE, $forum->id)
                ) {
                    $place = $forum->title;
                    $url = $this->urls->generate(CoreRoutes::THREAD_CREATE, ['forum' => (string) $forum->id]);
                }
            } else {
                $thread = $this->threads->find((int) $match[2]);

                if (
                    $thread !== null && $thread->status === ThreadStatus::Visible
                    && $thread->movedToThreadId === null && $scope->contains($thread->forumId)
                ) {
                    $place = $thread->title;
                    $url = $this->urls->generate(CoreRoutes::THREAD_REPLY, ['thread' => (string) $thread->id]);
                }
            }

            $items[] = ['context' => $draft['context'], 'kind' => $match[1], 'place' => $place, 'url' => $url,
                'title' => $draft['title'], 'excerpt' => (string) grapheme_substr($draft['content'], 0, 200),
                'updated' => new DateTimeImmutable($draft['updated_at'], new DateTimeZone('UTC'))];
        }

        $deleted = $request->query->get('deleted');

        return new Response($this->views->render('threads/drafts.twig', [
            'title' => $this->translator->translate('community.drafts'),
            'items' => $items,
            'deleted' => is_string($deleted) && ctype_digit($deleted) ? (int) $deleted : null,
            'action' => $this->urls->generate(CoreRoutes::DRAFTS),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]), Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
