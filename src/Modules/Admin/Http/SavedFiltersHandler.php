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
use Nolviko\Modules\Admin\Domain\AdminFilterSchema;
use Nolviko\Modules\Admin\Infrastructure\SavedAdminFilters;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Own saved searches only; URLs are reconstructed from a fixed context and validated filter fields. */
final readonly class SavedFiltersHandler implements RouteHandler
{
    private const CONTEXTS = ['settings' => 'admin.nav.settings', 'members' => 'admin.nav.members',
        'audit' => 'admin.nav.audit'];

    public function __construct(
        private SavedAdminFilters $filters,
        private RequestSubject $subjects,
        private CsrfTokens $csrf,
        private Session $session,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
        private SettingsCatalog $settings,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $input = $request->isMethod('POST') ? $request->request->all() : $request->query->all();
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $context = $text('context') === '' ? 'settings' : $text('context');
        $name = $text('name');
        $values = [];
        $rows = [];
        $error = null;
        $status = 200;
        $flash = '_saved_filter_notice:' . ($subject->userId ?? 0);
        $notice = $this->session->get($flash);
        $this->session->remove($flash);

        try {
            if (!isset(self::CONTEXTS[$context]) || (isset($input['f']) && !is_array($input['f']))) {
                throw new InvalidArgumentException('admin_filter_invalid');
            }
            $values = AdminFilterSchema::clean($context, $input['f'] ?? []);
            if ($request->isMethod('POST')) {
                if (!$this->csrf->isValid($text(CsrfTokens::FIELD))) {
                    throw new PermissionError('admin_filter_denied');
                }
                if ($text('action') === 'save') {
                    $this->filters->save($subject, $name, $context, $values);
                } elseif ($text('action') === 'delete' && preg_match('/^[1-9][0-9]{0,17}$/D', $text('id')) === 1) {
                    $this->filters->remove($subject, (int) $text('id'));
                } else {
                    throw new InvalidArgumentException('admin_filter_invalid');
                }
                $this->session->set($flash, $this->translator->translate('admin.saved.updated'));

                return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_SAVED_FILTERS)
                    . '?' . http_build_query(['context' => $context]));
            }
            foreach ($this->filters->all($subject) as $row) {
                $url = null;
                try {
                    $clean = AdminFilterSchema::clean(
                        $row['context'],
                        json_decode($row['filters_json'], true, 16, JSON_THROW_ON_ERROR)
                    );
                    $route = match ($row['context']) {
                        'settings' => CoreRoutes::ADMIN_SETTINGS,
                        'members' => CoreRoutes::ADMIN_MEMBERS,
                        'audit' => CoreRoutes::ADMIN_AUDIT,
                    };
                    $url = $this->urls->generate($route) . ($clean === [] ? '' : '?'
                        . http_build_query($clean, '', '&', PHP_QUERY_RFC3986));
                } catch (Throwable) {
                    // An invalid stored record is removable but never an executable/redirectable instruction.
                }
                $rows[] = ['id' => (string) $row['id'], 'name' => $row['name'], 'url' => $url];
            }
        } catch (PermissionError) {
            $status = 403;
            $error = $this->translator->translate('admin.saved.denied');
        } catch (InvalidArgumentException $failure) {
            $status = 422;
            $key = match ($failure->getMessage()) {
                'admin_filter_name_taken' => 'admin.saved.name_taken',
                'admin_filter_limit' => 'admin.saved.limit',
                'admin_filter_name_invalid' => 'admin.saved.name_invalid',
                default => 'admin.saved.invalid',
            };
            $error = $this->translator->translate($key);
        } catch (Throwable) {
            $status = 503;
            $error = $this->translator->translate('admin.saved.unavailable');
        }
        if (!isset(self::CONTEXTS[$context])) {
            $context = 'settings';
        }
        $fields = [];
        $labels = ['q' => 'admin.settings.search', 'group' => 'admin.settings.group', 'name' => 'admin.members.name',
            'status' => 'admin.saved.status', 'family' => 'admin.saved.family', 'actor' => 'admin.saved.actor',
            'target_type' => 'admin.saved.target_type', 'target_id' => 'admin.saved.target_id'];
        foreach (AdminFilterSchema::FIELDS[$context] as $key) {
            $options = [];
            if ($key === 'group') {
                foreach ($this->settings->groups() as $group) {
                    $options[] = ['value' => $group['id'], 'label' => $group['label']];
                }
            } elseif ($key === 'status') {
                foreach (UserStatus::cases() as $state) {
                    $options[] = ['value' => $state->value,
                        'label' => $this->translator->translate('admin.members.status', ['status' => $state->value])];
                }
            } elseif ($key === 'family') {
                foreach (AuditLogHandler::FAMILIES as $value => $label) {
                    $options[] = ['value' => $value, 'label' => $this->translator->translate($label)];
                }
            }
            $fields[] = ['key' => $key, 'label' => $this->translator->translate($labels[$key]),
                'value' => $values[$key] ?? '', 'options' => $options];
        }
        $contexts = [];
        foreach (self::CONTEXTS as $id => $label) {
            $contexts[] = ['id' => $id, 'label' => $this->translator->translate($label)];
        }

        return new Response($this->views->render('admin/saved-filters.twig', [
            'title' => $this->translator->translate('admin.saved.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_SAVED_FILTERS),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_SAVED_FILTERS), 'rows' => $rows,
            'context' => $context, 'contexts' => $contexts, 'fields' => $fields,
            'name' => mb_check_encoding($name, 'UTF-8') ? mb_substr($name, 0, 80) : '',
            'error' => $error, 'notice' => is_string($notice) ? $notice : null,
            'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
            'actions' => ['save' => 'save', 'delete' => 'delete'],
        ]), $status, ['Cache-Control' => 'no-store, private']);
    }
}
