<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use InvalidArgumentException;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Infrastructure\ForumModeratorAssignments;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Domain\UsergroupRepository;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Explicit privileged changes; reads/list pagination have no mutation and no arbitrary permission editor. */
final readonly class ForumModeratorsHandler implements RouteHandler
{
    public function __construct(
        private ForumModeratorAssignments $assignments,
        private ForumRepository $forums,
        private UsergroupRepository $groups,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private Session $session,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $input = $request->isMethod('POST') ? $request->request->all() : $request->query->all();
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $number = static fn(string $value): ?int => preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1
            ? (int) $value : null;
        $forum = $number($text('forum'));
        $before = $number($text('before'));
        $error = null;
        $status = 200;
        $rows = [];
        $forums = [];
        $groups = [];
        $older = null;
        $flash = '_forum_moderator_notice:' . ($subject->userId ?? 0);
        $notice = $this->session->get($flash);
        $this->session->remove($flash);

        try {
            if ($request->isMethod('POST')) {
                if (!$this->csrf->isValid($text(CsrfTokens::FIELD)) || !$this->admin->isFresh()) {
                    throw new PermissionError('forum_moderator_denied');
                }
                if ($text('group_id') !== '' && $number($text('group_id')) === null) {
                    throw new InvalidArgumentException('forum_moderator_target_invalid');
                }
                if ($text('action') === 'assign' && $forum !== null) {
                    $changed = $this->assignments->assign(
                        $subject,
                        true,
                        $forum,
                        trim($text('member')),
                        $number($text('group_id'))
                    );
                } elseif ($text('action') === 'remove' && $number($text('id')) !== null) {
                    $changed = $this->assignments->remove($subject, true, (int) $text('id'));
                } else {
                    throw new InvalidArgumentException('forum_moderator_target_invalid');
                }
                $this->session->set($flash, $this->translator->translate(
                    $changed ? 'admin.forum_mod.updated' : 'admin.forum_mod.unchanged',
                ));

                return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_FORUM_MODERATORS)
                    . ($forum === null ? '' : '?forum=' . $forum));
            }
            $rows = $this->assignments->page($subject, $forum, $before);
            if (count($rows) > 50) {
                array_pop($rows);
                $older = $this->urls->generate(CoreRoutes::ADMIN_FORUM_MODERATORS) . '?'
                    . http_build_query(array_filter(['forum' => $forum, 'before' => $rows[count($rows) - 1]['id']]));
            }
            foreach ($this->forums->all() as $item) {
                $forums[] = ['id' => (string) $item->id, 'title' => $item->title];
            }
            foreach ($this->groups->all() as $item) {
                if ($item->id !== BuiltInGroup::Guests->value) {
                    $groups[] = ['id' => (string) $item->id, 'name' => $item->name];
                }
            }
        } catch (PermissionError) {
            $status = 403;
            $error = $this->translator->translate('admin.forum_mod.denied');
        } catch (InvalidArgumentException) {
            $status = 422;
            $error = $this->translator->translate('admin.forum_mod.invalid');
        } catch (Throwable) {
            $status = 503;
            $error = $this->translator->translate('admin.forum_mod.unavailable');
        }

        return new Response($this->views->render('admin/forum-moderators.twig', [
            'title' => $this->translator->translate('admin.forum_mod.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_FORUM_MODERATORS),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_FORUM_MODERATORS), 'rows' => $rows,
            'forums' => $forums, 'groups' => $groups, 'selected_forum' => $forum === null ? '' : (string) $forum,
            'member' => mb_check_encoding($text('member'), 'UTF-8') ? mb_substr($text('member'), 0, 64) : '',
            'error' => $error, 'notice' => is_string($notice) ? $notice : null, 'older_url' => $older,
            'fresh' => $this->admin->isFresh(), 'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
            'actions' => ['assign' => 'assign', 'remove' => 'remove'],
        ]), $status, ['Cache-Control' => 'no-store, private']);
    }
}
