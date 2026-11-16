<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Groups\Domain\UsergroupRepository;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Users\Domain\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET `/admin/permissions` (M04.04, `admin.access` via AdminGuard): "what may this member do here, and why".
 * Pick a member (or the guest), a permission and a forum (or none); the page shows the resulting value and every
 * rule the resolver consulted, with its level and source (ADR-011 trace). Read only: nothing is changed.
 */
final class PermissionSimulatorHandler implements RouteHandler
{
    private const FORUM = '/^[1-9][0-9]{0,17}$/D';

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly PermissionCatalog $catalog,
        private readonly PermissionResolver $resolver,
        private readonly UserRepository $users,
        private readonly UsergroupRepository $groups,
        private readonly ForumRepository $forums,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $query = $request->query;
        $member = is_string($query->get('member')) ? mb_substr(trim((string) $query->get('member')), 0, 64) : '';
        $key = is_string($query->get('permission')) ? (string) $query->get('permission') : '';
        $forumText = is_string($query->get('forum')) ? (string) $query->get('forum') : '';
        $forumId = preg_match(self::FORUM, $forumText) === 1 ? (int) $forumText : null;
        // The production catalog is strict and throws for an unknown (or empty) key; here that means "no result".
        try {
            $definition = $this->catalog->find($key);
        } catch (PermissionError) {
            $definition = null;
        }
        $result = null;
        $error = null;

        if ($definition !== null) {
            $user = $member === '' ? null : $this->users->findByUsername($member);

            if ($member !== '' && $user === null) {
                $error = $this->translator->translate('admin.permissions.unknown_member');
            } elseif ($forumId !== null && $this->forums->find($forumId) === null) {
                $error = $this->translator->translate('admin.permissions.unknown_forum');
            } else {
                $subject = $user === null
                    ? $this->resolver->guest()
                    : $this->resolver->user($user->id, $this->groups->groupIdsOf($user->id));
                $trace = $subject->explain($definition->key, $forumId);
                $result = [
                    'value' => $this->show($trace->decision->value),
                    'reason' => $trace->decision->reason,
                    'rules' => array_map(fn(array $rule): array => [
                        'level' => $rule['level'],
                        'forum' => $rule['forum_id'] === null ? '' : (string) $rule['forum_id'],
                        'source' => str_starts_with($rule['source'], 'forum_moderator:')
                            ? $this->translator->translate(
                                'admin.forum_mod.trace',
                                ['principal' => substr($rule['source'], 16)]
                            ) : $rule['source'],
                        'value' => $rule['value'],
                    ], $trace->rules),
                ];
            }
        } elseif ($key !== '') {
            $error = $this->translator->translate('admin.permissions.unknown_permission');
        }

        $forums = [];

        foreach ($this->forums->all() as $forum) {
            $forums[] = [
                'value' => (string) $forum->id,
                'label' => $forum->title,
                'selected' => $forum->id === $forumId,
            ];
        }

        $permissions = [];

        foreach ($this->catalog->all() as $item) {
            $permissions[] = ['value' => $item->key, 'selected' => $item->key === $key];
        }

        $html = $this->views->render('admin/permissions.twig', [
            'title' => $this->translator->translate('admin.permissions.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_PERMISSIONS),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_PERMISSIONS),
            'member' => $member,
            'permissions' => $permissions,
            'forums' => $forums,
            'result' => $result,
            'error' => $error,
        ]);

        return new Response($html, $error === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function show(bool|int|string $value): string
    {
        return match (true) {
            $value === true => $this->translator->translate('admin.permissions.allowed'),
            $value === false => $this->translator->translate('admin.permissions.denied'),
            default => (string) $value,
        };
    }
}
