<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminGuard;
use Nolviko\Modules\Moderation\Domain\BulkModeration;
use Nolviko\Modules\Moderation\Domain\ModerationQueue;
use Nolviko\Modules\Moderation\Domain\QueueItem;
use Nolviko\Modules\Moderation\Domain\ResolveReport;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The moderator queue (M04.07): open reports, posts waiting for approval and accounts waiting for approval, in
 * tabs, newest first, PER_PAGE rows per keyset page (`?before=<id>`). Each tab is limited to the forums where
 * the moderator holds its permission (`moderation.report.handle`, `moderation.post.hide`; accounts need
 * `admin.access`) and can read. Someone with none of them gets 403; guests go to the login.
 */
final class ModerationQueueHandler implements RouteHandler
{
    public const PER_PAGE = 25;

    public const REPORTS = 'reports';

    public const POSTS = 'posts';

    public const USERS = 'users';

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly ModerationQueue $queue,
        private readonly ForumVisibility $visibility,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->subjects->userId($request) === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $subject = $this->subjects->of($request);
        $scopes = [
            self::REPORTS => $this->scope($subject, ResolveReport::PERMISSION),
            self::POSTS => $this->scope($subject, ModerateContent::MODERATE),
        ];
        $tabs = array_keys(array_filter($scopes, static fn(ForumScope $scope): bool => $scope->forumIds !== []));

        if ($subject->allows(AdminGuard::PERMISSION)) {
            $tabs[] = self::USERS;
        }

        $tab = (string) $request->query->get('tab', $tabs[0] ?? '');
        $beforeText = $request->query->get('before');
        $before = is_string($beforeText) && ctype_digit($beforeText) ? (int) $beforeText : null;

        if ($tabs === []) {
            return $this->errors->denied();
        }

        if (!in_array($tab, $tabs, true) || ($beforeText !== null && $before === null)) {
            return $this->errors->notFound();
        }

        $items = match ($tab) {
            self::REPORTS => $this->queue->openReports($scopes[self::REPORTS], self::PER_PAGE + 1, $before),
            self::POSTS => $this->queue->pendingPosts($scopes[self::POSTS], self::PER_PAGE + 1, $before),
            default => $this->queue->pendingUsers(self::PER_PAGE + 1, $before),
        };
        $more = count($items) > self::PER_PAGE;
        $items = array_slice($items, 0, self::PER_PAGE);
        $base = $this->urls->generate(CoreRoutes::MODERATION_QUEUE);
        $html = $this->views->render('moderation/queue.twig', [
            'title' => $this->translator->translate('moderation.queue.title'),
            'tab' => $tab,
            'tabs' => array_combine(
                $tabs,
                array_map(static fn(string $name): string => $base . '?tab=' . $name, $tabs),
            ),
            'rows' => array_map(fn(QueueItem $item): QueueRowView => $this->row($tab, $item), $items),
            'older' => $more ? $base . '?tab=' . $tab . '&before=' . $items[count($items) - 1]->id : null,
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'choices' => ['resolve' => ResolveReport::RESOLVE, 'dismiss' => ResolveReport::DISMISS],
            'bulk_target' => match ($tab) {
                self::POSTS => BulkModeration::POSTS,
                self::USERS => BulkModeration::USERS,
                default => null,
            },
            'bulk_url' => $this->urls->generate(CoreRoutes::MODERATION_BULK),
            'bulk' => ['approve' => BulkModeration::APPROVE, 'reject' => BulkModeration::REJECT],
        ]);

        return new Response($html, Response::HTTP_OK, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Forums where the key is allowed and whose threads the subject can read.
     */
    private function scope(SubjectPermissions $subject, string $key): ForumScope
    {
        $readable = $this->visibility->readable($subject);

        return new ForumScope(array_values(array_filter(
            $subject->forumsAllowing($key),
            static fn(int $forumId): bool => $readable->contains($forumId),
        )));
    }

    private function row(string $tab, QueueItem $item): QueueRowView
    {
        $postUrl = $item->postId === null
            ? null
            : $this->urls->generate(CoreRoutes::POST_SHOW, ['post' => (string) $item->postId]);
        $actionUrl = match ($tab) {
            self::REPORTS => $this->urls->generate(CoreRoutes::MODERATION_REPORT, ['report' => (string) $item->id]),
            self::POSTS => $this->urls->generate(CoreRoutes::POST_MODERATE, ['post' => (string) $item->id]),
            default => null,
        };

        return new QueueRowView(
            $item->id,
            $item->createdAt,
            $item->threadTitle,
            $item->excerpt,
            $item->authorName,
            $item->reason,
            $item->reporterName,
            $item->username,
            $item->email,
            $postUrl,
            $actionUrl,
        );
    }
}
