<?php

declare(strict_types=1);

namespace Nolviko\Modules\Calendar\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Calendar\Infrastructure\CalendarEvents;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Http\ThreadPageHandler;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/calendar` (beta.18 roadmap 38): events of readable threads in one month (`?month=YYYY-MM`). POST sets or
 * removes a thread's event, by the thread's author or a moderator of its forum, then returns to the thread.
 */
final readonly class CalendarHandler implements RouteHandler
{
    private const MANAGE = 'moderation.post.edit_any';

    public function __construct(
        private CalendarEvents $events,
        private ThreadRepository $threads,
        private ForumVisibility $visibility,
        private RequestSubject $subjects,
        private CsrfTokens $csrf,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
        private ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $scope = $this->visibility->readable($subject);

        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
            $thread = $this->threads->find(ctype_digit($text('thread')) ? (int) $text('thread') : 0);

            if (
                $thread === null || $subject->userId === null || !$this->csrf->isValid($text(CsrfTokens::FIELD))
                || !$scope->contains($thread->forumId)
                || ($thread->userId !== $subject->userId && !$subject->allows(self::MANAGE, $thread->forumId))
            ) {
                return $this->errors->notFound();
            }

            $saved = $this->events->set($thread->id, $subject->userId, $text('starts_on'), $text('ends_on'));

            return new Response('', Response::HTTP_SEE_OTHER, ['Location' => ThreadPageHandler::url(
                $this->urls,
                $thread->id,
                1,
                $thread->slug,
            ) . ($saved ? '' : '?event=invalid'), 'Cache-Control' => 'private, no-store']);
        }

        $month = $request->query->get('month');
        $first = is_string($month) && preg_match('/^(19|20|21)[0-9]{2}-(0[1-9]|1[0-2])$/D', $month) === 1
            ? new DateTimeImmutable($month . '-01', new DateTimeZone('UTC'))
            : new DateTimeImmutable('first day of this month 00:00', new DateTimeZone('UTC'));
        $base = $this->urls->generate(CoreRoutes::CALENDAR);
        $events = array_map(fn(array $event): array => $event + ['url' => ThreadPageHandler::url(
            $this->urls,
            $event['thread_id'],
            1,
            $event['slug'],
        )], $this->events->month($scope, $first));

        $html = $this->views->render('calendar/month.twig', [
            'title' => $this->translator->translate('calendar.title'),
            'month' => $first->format('Y-m'),
            'events' => $events,
            'previous_url' => $base . '?month=' . $first->modify('-1 month')->format('Y-m'),
            'next_url' => $base . '?month=' . $first->modify('+1 month')->format('Y-m'),
        ]);

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-cache']);
    }
}
