<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Closure;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Infrastructure\ForumAdmin;
use Nolviko\Modules\Admin\Infrastructure\ForumGuestVisibility;
use Nolviko\Modules\Forums\Domain\Forum;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Domain\ForumRuleViolation;
use Nolviko\Modules\Forums\Domain\ForumType;
use Nolviko\Modules\Forums\Infrastructure\ForumExtras;
use Nolviko\Modules\Moderation\Infrastructure\ModerationActions;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;
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

    /** Site-wide default visibility for guests (beta.10). */
    public const SITE_VISIBILITY = 'site_visibility';

    /** beta.18 (G4): link target, post counting, guest posting and the forum password. */
    public const EXTRAS = 'extras';

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
        private readonly ?ForumGuestVisibility $visibility = null,
        private readonly ?PermissionResolver $resolver = null,
        private readonly ?ForumExtras $extras = null,
        private readonly ?ModerationActions $modActions = null,
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
                self::CREATE => $this->together(function () use ($actor, $text, $id): void {
                    $created = $this->admin->create(
                        $actor,
                        ForumType::tryFrom($text('type')) ?? ForumType::Forum,
                        $text('title'),
                        trim($text('slug')) !== '' ? $text('slug') : (CreateThread::slug($text('title')) ?: 'forum'),
                        $id('parent'),
                    );
                    $this->visibility?->apply($created->id, $text('visibility'));
                }),
                self::SITE_VISIBILITY => $this->visibility?->apply(null, $text('visibility')),
                self::MOVE => $this->admin->moveUnder($actor, $forum, $id('parent')),
                self::UP => $this->admin->shift($actor, $forum, -1),
                self::DOWN => $this->admin->shift($actor, $forum, 1),
                self::EDIT => $this->together(function () use ($actor, $forum, $text): void {
                    $this->admin->update(
                        $actor,
                        $forum,
                        $text('title'),
                        $text('slug'),
                        $text('description'),
                        // Only forums carry the switch; a category's form has no such field.
                        $text('kind') === 'forum' ? $text('questions') === '1' : null,
                    );
                    // Unchanged or "custom" keeps every existing rule of the forum.
                    $current = $this->visibility?->presets([$forum])[$forum] ?? null;
                    if ($text('visibility') !== '' && $text('visibility') !== $current) {
                        $this->visibility?->apply($forum, $text('visibility'));
                    }
                }),
                // A password change needs a fresh administrator session, like deleting a forum.
                self::EXTRAS => ($text('password_action') === 'keep' || $text('password_action') === ''
                    || $this->session->isFresh()) ? $this->extras?->save($forum, $input)
                    : throw new ForumRuleViolation('admin_reauth_required'),
                'mod_action_add' => $this->modActions?->add(
                    $text('mod_title'),
                    $id('mod_move'),
                    $text('mod_lock'),
                    $text('mod_sticky'),
                    $id('mod_prefix'),
                    $text('mod_reply'),
                ),
                'mod_action_delete' => $this->modActions?->delete($id('mod_id') ?? 0),
                self::LOCK => $this->admin->setLocked($actor, $forum, true),
                self::UNLOCK => $this->admin->setLocked($actor, $forum, false),
                self::DELETE => $this->session->isFresh()
                    ? $this->admin->delete($actor, $forum)
                    : throw new ForumRuleViolation('admin_reauth_required'),
                default => throw new ForumRuleViolation('moderation_action_invalid'),
            };
        } catch (ForumRuleViolation | ThreadRuleViolation $violation) {
            $status = $violation->diagnostic === 'admin_reauth_required'
                ? Response::HTTP_FORBIDDEN
                : Response::HTTP_UNPROCESSABLE_ENTITY;

            return $this->page($this->translator->translate('diagnostics.' . $violation->diagnostic), $status);
        }

        return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_FORUMS));
    }

    /** Forum change and its visibility rules in one transaction (beta.10). */
    private function together(Closure $work): void
    {
        if ($this->visibility === null) {
            $work();

            return;
        }
        $this->visibility->transaction($work);
    }

    /** What a guest gets for one forum: the whole forum, its name only, or nothing. */
    private function guestSees(int $forumId): string
    {
        $guest = $this->resolver?->guest();
        if ($guest === null) {
            return '';
        }
        if (!$guest->allows(ForumVisibility::VIEW, $forumId)) {
            return $this->translator->translate('admin.forums.guest_nothing');
        }

        return $this->translator->translate($guest->allows(ForumVisibility::READ, $forumId)
            ? 'admin.forums.guest_all' : 'admin.forums.guest_name');
    }

    private function page(?string $error, int $status): Response
    {
        $forums = $this->forums->all();
        $presets = $this->visibility?->presets(array_map(static fn(Forum $forum): int => $forum->id, $forums)) ?? [];
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
                    match ($forum->type) {
                        ForumType::Category => 'admin.forums.category',
                        ForumType::Link => 'admin.forums.link',
                        default => 'admin.forums.forum',
                    },
                ),
                'locked' => $forum->isLocked,
                'parent' => $forum->parentId === null ? '' : (string) $forum->parentId,
                'slug' => $forum->slug,
                'description' => $forum->description ?? '',
                'is_forum' => $forum->type === ForumType::Forum,
                'is_link' => $forum->type === ForumType::Link,
                'link_url' => $forum->linkUrl ?? '',
                'link_counts' => $forum->linkCounts,
                'link_clicks' => $forum->linkClicks,
                'counts_posts' => $forum->countsPosts,
                'has_password' => $forum->hasPassword,
                'questions' => $forum->questions,
                'visibility' => $presets[$forum->id] ?? '',
                'guest_sees' => $this->guestSees($forum->id),
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
                ['value' => ForumType::Link->value, 'label' => $this->translator->translate('admin.forums.link')],
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
                'extras' => self::EXTRAS,
            ],
            'mod_actions' => $this->modActions?->all(),
            'mod_forums' => $hosts,
            'visibility' => $this->visibility === null ? null : [
                'site' => $presets[0] ?? ForumGuestVisibility::PUBLIC,
                'forum_choices' => ForumGuestVisibility::FORUM_CHOICES,
                'site_choices' => ForumGuestVisibility::SITE_CHOICES,
                'action' => self::SITE_VISIBILITY,
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
