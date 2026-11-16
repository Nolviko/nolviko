<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Http;

use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Http\TransportPolicy;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminGuard;
use Nolviko\Modules\Admin\Http\AdminNavigation;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Importers\Infrastructure\ImportAdministration;
use Nolviko\Modules\Users\Http\RequestSubject;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** No source passwords/paths in HTTP fields. Every mutation uses the existing fresh-admin HTTPS/CSRF boundary. */
final readonly class ImportsHandler implements RouteHandler
{
    public function __construct(
        private ImportAdministration $imports,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private DbalAdminAudit $audit,
        private TemplateRenderer $views,
        private MessageTranslator $translator,
        private UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        if ($subject->userId === null || !$subject->allows(AdminGuard::PERMISSION)) {
            return new Response('', 403, ['Cache-Control' => 'private, no-store']);
        }
        $input = $request->isMethod('POST') ? $request->request->all() : $request->query->all();
        $job = is_string($input['job'] ?? null) ? $input['job'] : null;
        $error = null;
        $status = 200;
        $selected = null;
        $report = null;
        $recent = [];
        $sources = [];
        try {
            if ($request->isMethod('POST')) {
                if (
                    !TransportPolicy::allows($request) || !$this->admin->isFresh()
                    || !is_string($input[CsrfTokens::FIELD] ?? null) || !$this->csrf->isValid($input[CsrfTokens::FIELD])
                ) {
                    throw new RuntimeException('import_denied');
                }
                $action = $input['action'] ?? null;
                if (!is_string($action) || !in_array($action, ['begin', 'step'], true)) {
                    throw new RuntimeException('import_denied');
                }
                $this->audit->record($subject->userId, 'admin.import_' . $action . '_requested');
                if ($action === 'begin') {
                    if (!is_string($input['source'] ?? null)) {
                        throw new RuntimeException('import_source_missing');
                    }
                    $job = $this->imports->begin(
                        $input['source'],
                        $subject->userId,
                        ($input['acknowledged'] ?? null) === '1'
                    );
                } else {
                    $revision = $input['revision'] ?? null;
                    if ($job === null || !is_string($revision) || preg_match('/^[0-9]{1,18}$/D', $revision) !== 1) {
                        throw new RuntimeException('import_checkpoint_conflict');
                    }
                    $this->imports->step($job, (int) $revision);
                }
                $this->audit->record($subject->userId, 'admin.import_' . $action . '_completed');
            }
            $sources = $this->imports->sources();
            $recent = $this->imports->recent();
            if ($job !== null) {
                $selected = $this->imports->job($job);
                $report = $this->imports->report($job);
            }
        } catch (Throwable $failure) {
            $status = $failure->getMessage() === 'import_denied' ? 403 : 422;
            $error = $this->translator->translate(match ($failure->getMessage()) {
                'import_denied' => 'operations.denied',
                'import_identity_collision', 'import_existing_user_map_denied' => 'operations.collision',
                'import_confirmation_required' => 'operations.import_confirm_required',
                'import_attachment_root_required', 'import_attachment_missing', 'import_attachment_invalid'
                    => 'operations.import_files_needed',
                'import_checkpoint_conflict' => 'operations.import_reload',
                'import_parity_mismatch', 'import_count_mismatch', 'import_source_changed'
                    => 'operations.import_changed',
                default => 'operations.import_failed',
            });
        }
        if ($selected === null && $job !== null && $status !== 403) {
            try {
                $selected = $this->imports->job($job);
                $report = $this->imports->report($job);
                $recent = $this->imports->recent();
            } catch (Throwable) {
                // Keep the original localized error and expose no database or source details.
            }
        }
        if ($report !== null) {
            foreach ($report['notes'] as &$note) {
                $note['label'] = $this->translator->translate(match ($note['reason']) {
                    'password_reset_required' => 'operations.note_password',
                    'existing_owner_explicitly_linked' => 'operations.note_linked',
                    'recipient_deleted', 'system_identity' => 'operations.note_identity',
                    'unowned_source_attachment' => 'operations.note_unowned',
                    'orphan_file_owner_archive' => 'operations.note_orphan',
                    'original_file_retained' => 'operations.note_file',
                    default => 'operations.note_aggregate',
                });
            }
            unset($note);
        }
        foreach ($recent as &$recentJob) {
            $recentJob['url'] = $this->urls->generate(CoreRoutes::ADMIN_IMPORTS, ['job' => $recentJob['id']]);
        }
        unset($recentJob);
        return new Response($this->views->render('admin/imports.twig', [
            'title' => $this->translator->translate('operations.imports'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_IMPORTS),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_IMPORTS), 'sources' => $sources,
            'recent' => $recent, 'selected' => $selected, 'report' => $report, 'error' => $error,
            'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer']);
    }
}
