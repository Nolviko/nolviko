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
use Nolviko\Modules\Admin\Infrastructure\GroupAdministration;
use Nolviko\Modules\Groups\Domain\GroupRuleViolation;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** `/admin/groups` (M04.14): every group with its member count; create and delete custom groups. */
final readonly class GroupsHandler implements RouteHandler
{
    public function __construct(
        private GroupAdministration $groups,
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
        $input = $request->request->all();
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $flash = '_group_notice:' . ($subject->userId ?? 0);
        $notice = $this->session->get($flash);
        $this->session->remove($flash);
        $error = null;
        $status = 200;
        $rows = [];

        try {
            if ($request->isMethod('POST')) {
                if (!$this->csrf->isValid($text(CsrfTokens::FIELD))) {
                    throw new PermissionError('group_admin_denied');
                }

                $fresh = $this->admin->isFresh();
                $id = preg_match('/^[1-9][0-9]{0,17}$/D', $text('group')) === 1 ? (int) $text('group') : 0;
                $target = match ($text('action')) {
                    'create' => $this->groups->create($subject, $fresh, $text('name'), $text('slug'))->id,
                    'delete' => $this->deleted($subject, $fresh, $id),
                    default => throw new InvalidArgumentException('group_action_invalid'),
                };
                $this->session->set($flash, $this->translator->translate('admin.groups.saved'));

                return InstallerPage::redirect($target > 0
                    ? $this->urls->generate(CoreRoutes::ADMIN_GROUP, ['group' => (string) $target])
                    : $this->urls->generate(CoreRoutes::ADMIN_GROUPS));
            }
        } catch (PermissionError) {
            [$status, $error] = [403, $this->translator->translate('admin.groups.denied')];
        } catch (GroupRuleViolation $violation) {
            [$status, $error] = [422, $this->errorText($violation->diagnostic)];
        } catch (InvalidArgumentException $failure) {
            [$status, $error] = [422, $this->errorText($failure->getMessage())];
        } catch (Throwable) {
            [$status, $error] = [503, $this->translator->translate('admin.groups.unavailable')];
        }

        try {
            foreach ($this->groups->overview() as $row) {
                $rows[] = $row + ['url' => $this->urls->generate(
                    CoreRoutes::ADMIN_GROUP,
                    ['group' => (string) $row['id']],
                )];
            }
        } catch (Throwable) {
            [$status, $error] = [503, $this->translator->translate('admin.groups.unavailable')];
        }

        return new Response($this->views->render('admin/groups.twig', [
            'title' => $this->translator->translate('admin.groups.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_GROUPS),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_GROUPS), 'rows' => $rows,
            'name' => mb_check_encoding($text('name'), 'UTF-8') ? mb_substr($text('name'), 0, 100) : '',
            'slug' => preg_match('/^[a-z0-9-]{0,64}$/D', $text('slug')) === 1 ? $text('slug') : '',
            'error' => $error, 'notice' => is_string($notice) ? $notice : null,
            'fresh' => $this->admin->isFresh(), 'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /** Deletes, then answers 0 so the caller lands back on the list. */
    private function deleted(SubjectPermissions $subject, bool $fresh, int $id): int
    {
        $this->groups->delete($subject, $fresh, $id);

        return 0;
    }

    /** Diagnostic ID => translated text (IDs are the `admin.groups.error.*` keys). */
    private function errorText(string $diagnostic): string
    {
        $key = 'admin.groups.error.' . $diagnostic;

        return $this->translator->has($key) ? $this->translator->translate($key)
            : $this->translator->translate('admin.groups.unavailable');
    }
}
