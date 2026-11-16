<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Infrastructure\ForumAdmin;
use Nolviko\Modules\Forums\Domain\Forum;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumRuleViolation;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/admin/forums` (M04.05, `admin.access` via AdminGuard): the forum tree with plain buttons instead of dragging
 * — create, move under another parent, one place up or down, lock or unlock, delete (empty forums only, after a
 * fresh password confirmation). POST `/admin/forums/action` is CSRF-checked by the kernel guard.
 */
final class ForumManagementHandler implements RouteHandler
{
    public const CREATE = 'create';

    public const MOVE = 'move';

    public const UP = 'up';

    public const DOWN = 'down';

    public const LOCK = 'lock';

    public const UNLOCK = 'unlock';

    public const DELETE = 'delete';

    public const EDIT = 'edit';

    private const ID = '/^[1-9][0-9]{0,17}$/D';

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly ForumRepository $forums,
        private readonly ForumAdmin $admin,
        private readonly AdminSession $session,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly bool $action,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if (!$this->action) {
            return $this->page(null, Response::HTTP_OK);
        }

        $input = $request->request->all();
        $text = static fn(string $name): string => is_string($input[$name] ?? null) ? $input[$name] : '';
        $id = static fn(string $name): ?int => preg_match(self::ID, $text($name)) === 1 ? (int) $text($name) : null;
        $actor = (int) $this->subjects->userId($request);
        $forum = $id('forum') ?? 0;

        try {
            match ($text('action')) {
                // 2026-11-09: an empty address is made from the title (as for thread addresses).
                self::CREATE => $this->admin->create(
                    $actor,
                    ForumType::tryFrom($text('type')) ?? ForumType::Forum,
                    $text('title'),
                    trim($text('slug')) !== '' ? $text('slug') : (CreateThread::slug($text('title')) ?: 'forum'),
                    $id('parent'),
                ),
                self::MOVE => $this->admin->moveUnder($actor, $forum, $id('parent')),
                self::UP => $this->admin->shift($actor, $forum, -1),
                self::DOWN => $this->admin->shift($actor, $forum, 1),
                self::EDIT => $this->admin->update(
                    $actor,
                    $forum,
                    $text('title'),
                    $text('slug'),
                    $text('description'),
                    // Only forums carry the switch; a category's form has no such field.
                    $text('kind') === 'forum' ? $text('questions') === '1' : null,
                ),
                self::LOCK => $this->admin->setLocked($actor, $forum, true),
                self::UNLOCK => $this->admin->setLocked($actor, $forum, false),
                self::DELETE => $this->session->isFresh()
                    ? $this->admin->delete($actor, $forum)
                    : throw new ForumRuleViolation('admin_reauth_required'),
                default => throw new ForumRuleViolation('moderation_action_invalid'),
            };
        } catch (ForumRuleViolation $violation) {
            $status = $violation->diagnostic === 'admin_reauth_required'
                ? Response::HTTP_FORBIDDEN
                : Response::HTTP_UNPROCESSABLE_ENTITY;

            return $this->page($this->translator->translate('diagnostics.' . $violation->diagnostic), $status);
        }

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_FORUMS));
    }

    private function page(?string $error, int $status): Response
    {
        $forums = $this->forums->all();
        $parents = [['value' => '', 'label' => $this->translator->translate('admin.forums.root')]];
        $categories = [];
        $hosts = [];
        $rows = [];

        foreach ($forums as $forum) {
            $parents[] = [
                'value' => (string) $forum->id,
                'label' => str_repeat("\u{00A0}\u{00A0}", $forum->depth) . $forum->title,
            ];
            // Create form (2026-11-09): categories and forums a new forum can sit in, as two labelled groups.
            $option = ['value' => (string) $forum->id,
                'label' => str_repeat("\u{00A0}\u{00A0}", $forum->depth) . $forum->title];

            if ($forum->type === ForumType::Category) {
                $categories[] = $option;
            } else {
                $hosts[] = $option;
            }
            $rows[] = [
                'id' => (string) $forum->id,
                'title' => $forum->title,
                'indent' => $forum->depth,
                'kind' => $this->translator->translate(
                    $forum->type === ForumType::Category ? 'admin.forums.category' : 'admin.forums.forum',
                ),
                'locked' => $forum->isLocked,
                'parent' => $forum->parentId === null ? '' : (string) $forum->parentId,
                'slug' => $forum->slug,
                'description' => $forum->description ?? '',
                'is_forum' => $forum->type === ForumType::Forum,
                'questions' => $forum->questions,
            ];
        }

        $html = $this->views->render('admin/forums.twig', [
            'title' => $this->translator->translate('admin.forums.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_FORUMS),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_FORUMS_ACTION),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'rows' => $rows,
            'parents' => $parents,
            'categories' => $categories,
            'parent_forums' => $hosts,
            'types' => [
                ['value' => ForumType::Forum->value, 'label' => $this->translator->translate('admin.forums.forum')],
                [
                    'value' => ForumType::Category->value,
                    'label' => $this->translator->translate('admin.forums.category'),
                ],
            ],
            'choices' => [
                'create' => self::CREATE,
                'edit' => self::EDIT,
                'move' => self::MOVE,
                'up' => self::UP,
                'down' => self::DOWN,
                'lock' => self::LOCK,
                'unlock' => self::UNLOCK,
                'delete' => self::DELETE,
            ],
            'error' => $error,
            'fresh' => $this->session->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
        ]);

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
