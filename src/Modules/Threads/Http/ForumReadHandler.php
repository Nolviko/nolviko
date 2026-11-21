<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Http;

use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Threads\Domain\ReadTracker;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST `/forums/{forum}/read` (M03.12, CSRF-checked by the kernel guard): marks every thread of a forum the member
 * can read as read, then returns to the forum. Guests go to the login; a forum they cannot see is a 404.
 * POST `/forums/read` (beta.18, roadmap 12) marks every forum read with one board-wide cutoff and returns to the
 * board index.
 */
final class ForumReadHandler implements RouteHandler
{
    public function __construct(
        private readonly RequestSubject $subjects,
        private readonly ForumVisibility $visibility,
        private readonly ReadTracker $reads,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $userId = $this->subjects->userId($request);

        if ($userId === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        if (($parameters['_route'] ?? null) === CoreRoutes::FORUMS_READ_ALL) {
            $this->reads->markAll($userId, new DateTimeImmutable('now', new DateTimeZone('UTC')));

            return InstallerPage::redirect($this->urls->generate(CoreRoutes::HOME));
        }

        $forumId = (int) ($parameters['forum'] ?? 0);

        if (!$this->visibility->readable($this->subjects->of($request))->contains($forumId)) {
            return $this->errors->notFound();
        }

        $this->reads->markForum($userId, $forumId, new DateTimeImmutable('now', new DateTimeZone('UTC')));

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::FORUM_SHOW, ['forum' => (string) $forumId]));
    }
}
