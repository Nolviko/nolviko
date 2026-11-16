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
use Nolviko\Modules\Admin\Infrastructure\DbalMemberList;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `/admin/members` (M04.06, `admin.access` via AdminGuard): GET lists accounts newest first with a name prefix and
 * status filter and an "older" keyset link; POST `/admin/members/bulk` (CSRF-checked, fresh password confirmation)
 * suspends or reactivates the selected accounts, at most 100, never administrators or the acting admin. A
 * suspension also ends the account's sessions.
 */
final class MembersHandler implements RouteHandler
{
    public const SUSPEND = 'suspend';

    public const REACTIVATE = 'reactivate';

    private const ID = '/^[1-9][0-9]{0,17}$/D';

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly DbalMemberList $members,
        private readonly AdminSession $admin,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly bool $bulk,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $list = $this->urls->generate(CoreRoutes::ADMIN_MEMBERS);

        if ($this->bulk) {
            if (!$this->admin->isFresh()) {
                return $this->page($request, $this->translator->translate('diagnostics.admin_reauth_required'), 403);
            }

            $input = $request->request->all();
            $action = is_string($input['action'] ?? null) ? $input['action'] : '';
            $ids = array_map('intval', array_filter(
                is_array($input['members'] ?? null) ? $input['members'] : [],
                static fn(mixed $id): bool => is_string($id) && preg_match(self::ID, $id) === 1,
            ));

            if (!in_array($action, [self::SUSPEND, self::REACTIVATE], true) || $ids === []) {
                $error = $this->translator->translate('diagnostics.moderation_action_invalid');

                return $this->page($request, $error, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $changed = $this->members->setSuspended(
                array_values($ids),
                $action === self::SUSPEND,
                (int) $this->subjects->userId($request),
                null,
            );

            return InstallerPage::redirect($list . '?changed=' . $changed);
        }

        return $this->page($request, null, Response::HTTP_OK);
    }

    private function page(Request $request, ?string $error, int $status): Response
    {
        $query = $request->query;
        $name = is_string($query->get('name')) ? mb_substr(trim((string) $query->get('name')), 0, 64) : '';
        $state = UserStatus::tryFrom(is_string($query->get('status')) ? (string) $query->get('status') : '');
        $beforeText = is_string($query->get('before')) ? (string) $query->get('before') : '';
        $before = preg_match(self::ID, $beforeText) === 1 ? (int) $beforeText : null;
        try {
            $rows = $this->members->page($name, $state, $before);
        } catch (Throwable) {
            $rows = [];
            $status = 503;
            $error = $this->translator->translate('admin.saved.unavailable');
        }
        $older = null;

        if (count($rows) > DbalMemberList::PAGE_SIZE) {
            array_pop($rows);
            $older = $this->urls->generate(CoreRoutes::ADMIN_MEMBERS) . '?' . http_build_query(array_filter([
                'name' => $name,
                'status' => $state?->value,
                'before' => $rows[count($rows) - 1]['id'],
            ]));
        }

        $statuses = [[
            'value' => '',
            'label' => $this->translator->translate('admin.members.any'),
            'selected' => $state === null,
        ]];

        foreach (UserStatus::cases() as $case) {
            $statuses[] = [
                'value' => $case->value,
                'label' => $this->translator->translate('admin.members.status', ['status' => $case->value]),
                'selected' => $case === $state,
            ];
        }

        $changed = $query->get('changed');
        $saveFilterUrl = $this->urls->generate(CoreRoutes::ADMIN_SAVED_FILTERS) . '?'
                . http_build_query([
                    'context' => 'members',
                    'f' => ['name' => $name, 'status' => $state?->value ?? ''],
                ]);

        $html = $this->views->render('admin/members.twig', [
            'title' => $this->translator->translate('admin.members.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_MEMBERS),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_MEMBERS),
            'save_filter_url' => $saveFilterUrl,
            'bulk_action' => $this->urls->generate(CoreRoutes::ADMIN_MEMBERS_BULK),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'name' => $name,
            'statuses' => $statuses,
            'rows' => array_map(fn(array $row): array => [
                'id' => (string) $row['id'],
                'url' => $this->urls->generate(CoreRoutes::ADMIN_MEMBER, ['user' => (string) $row['id']]),
                'username' => $row['username'],
                'email' => $row['email'],
                'status' => $this->translator->translate('admin.members.status', ['status' => $row['status']]),
                'joined' => substr($row['created_at'], 0, 10),
            ], $rows),
            'choices' => ['suspend' => self::SUSPEND, 'reactivate' => self::REACTIVATE],
            'older_url' => $older,
            'error' => $error,
            'notice' => is_string($changed) && ctype_digit($changed)
                ? $this->translator->translate('admin.members.changed', ['count' => (int) $changed])
                : null,
            'fresh' => $this->admin->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
        ]);

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
