<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\ForumExtras;
use Nolviko\Modules\Forums\Infrastructure\ForumSubscriptions;
use Nolviko\Modules\Permissions\Domain\ForumLocks;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Three small forum routes of the beta.18 roadmap (G4):
 * - `/forums/{forum}/go` (06): a visible link section counts the click and redirects to its address;
 * - `/forums/{forum}/unlock` (43, ADR-041): the password form of a protected forum; a right password is kept in
 *   the session as a fingerprint of the stored hash (ForumLocks), one attempt per network every 3 seconds;
 * - `/forums/{forum}/subscribe` (01): a member subscribes to new threads of a readable forum, or stops.
 * Anything not visible to the visitor is a plain 404.
 */
final readonly class ForumGateHandler implements RouteHandler
{
    private const ATTEMPT_SECONDS = 3;

    public function __construct(
        private ForumRepository $forums,
        private ForumVisibility $visibility,
        private RequestSubject $subjects,
        private ForumExtras $extras,
        private ForumSubscriptions $subscriptions,
        private RateBuckets $buckets,
        private Session $session,
        private CsrfTokens $csrf,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
        private ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $forumId = (int) ($parameters['forum'] ?? 0);
        $forum = $this->forums->find($forumId);
        $subject = $this->subjects->of($request);

        if ($forum === null || !$this->visibility->visible($subject)->contains($forumId)) {
            return $this->errors->notFound();
        }

        $route = $parameters['_route'] ?? '';
        $show = $this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (string) $forumId]);

        if ($route === CoreRoutes::FORUM_LINK) {
            if ($forum->type !== ForumType::Link || $forum->linkUrl === null) {
                return $this->errors->notFound();
            }

            $this->extras->click($forumId);

            return new Response('', Response::HTTP_FOUND, ['Location' => $forum->linkUrl,
                'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'strict-origin-when-cross-origin']);
        }

        $token = $request->request->get(CsrfTokens::FIELD);
        $validPost = $request->isMethod('POST') && $this->csrf->isValid(is_string($token) ? $token : '');

        if ($route === CoreRoutes::FORUM_SUBSCRIBE) {
            if (
                $subject->userId === null || !$validPost
                || !$this->visibility->readable($subject)->contains($forumId)
            ) {
                return $this->errors->notFound();
            }

            $this->subscriptions->set($subject->userId, $forumId, $request->request->get('enable') === '1');

            return new Response('', Response::HTTP_SEE_OTHER, ['Location' => $show,
                'Cache-Control' => 'private, no-store']);
        }

        $locking = $subject->lockingForum($forumId);

        if ($locking === null) {
            return new Response('', Response::HTTP_SEE_OTHER, ['Location' => $show,
                'Cache-Control' => 'private, no-store']);
        }

        $error = null;
        $status = Response::HTTP_OK;

        if ($validPost) {
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $key = 'forum_password:' . hash('sha256', (string) $request->getClientIp() . '|' . $locking);
            $this->buckets->ensure($key, $now);
            $wait = $this->buckets->hit($key, self::ATTEMPT_SECONDS, $now);
            $password = $request->request->get('password');
            $fingerprint = $wait === 0 && is_string($password) ? $this->extras->unlock($locking, $password) : null;

            if ($fingerprint !== null) {
                $unlocked = $this->session->get(ForumLocks::SESSION_KEY);
                $unlocked = is_array($unlocked) ? $unlocked : [];
                $unlocked[$locking] = $fingerprint;
                $this->session->set(ForumLocks::SESSION_KEY, $unlocked);

                return new Response('', Response::HTTP_SEE_OTHER, ['Location' => $show,
                    'Cache-Control' => 'private, no-store']);
            }

            $error = $this->translator->translate($wait > 0 ? 'forums.unlock.wait' : 'forums.unlock.wrong', [
                'seconds' => $wait,
            ]);
            $status = $wait > 0 ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        $locked = $this->forums->find($locking);
        $html = $this->views->render('forums/unlock.twig', [
            'title' => $this->translator->translate('forums.unlock.title', ['forum' => $locked?->title ?? '']),
            'action' => $this->urls->generate(CoreRoutes::FORUM_UNLOCK, ['forum' => (string) $forumId]),
            'error' => $error,
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]);

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
    }
}
