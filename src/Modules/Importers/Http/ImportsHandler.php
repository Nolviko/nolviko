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
use Nolviko\Modules\Importers\Infrastructure\ImportPreflight;
use Nolviko\Modules\Importers\Infrastructure\ImportSources;
use Nolviko\Modules\Users\Http\RequestSubject;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Imports in the admin panel. Every change goes through the HTTPS/CSRF admin boundary. Since beta.11 the owner adds
 * the source here (its password is never shown again), the page keeps running batches by itself while it is open
 * (JSON `run`; without JavaScript one button press runs one request's worth of batches), and the finished import is
 * opened with an explicit "publish" decision. The summary can be downloaded as JSON.
 */
final readonly class ImportsHandler implements RouteHandler
{
    private const ACTIONS = ['add_source', 'remove_source', 'begin', 'step', 'run', 'publish'];

    private const SOURCE_FIELDS = ['engine', 'host', 'port', 'database', 'username', 'prefix', 'charset',
        'attachment_root'];

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
        $json = str_contains((string) $request->headers->get('Accept'), 'application/json');
        $error = null;
        $notice = null;
        $status = 200;
        $selected = null;
        $report = null;
        $recent = [];
        $sources = [];
        $preflight = null;
        $form = [];
        $action = null;
        if ($request->isMethod('GET') && $job !== null && ($input['format'] ?? null) === 'json') {
            return $this->download($job);
        }
        try {
            if ($request->isMethod('POST')) {
                if (
                    !TransportPolicy::allows($request) || !$this->admin->isFresh()
                    || !is_string($input[CsrfTokens::FIELD] ?? null) || !$this->csrf->isValid($input[CsrfTokens::FIELD])
                ) {
                    throw new RuntimeException('import_denied');
                }
                $action = $input['action'] ?? null;
                if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
                    throw new RuntimeException('import_denied');
                }
                if ($action !== 'run' && $action !== 'step') {
                    $this->audit->record($subject->userId, 'admin.import_' . $action . '_requested');
                }
                if ($action === 'add_source') {
                    foreach (self::SOURCE_FIELDS as $field) {
                        $form[$field] = is_string($input[$field] ?? null) ? $input[$field] : '';
                    }
                    $fields = $form;
                    $fields['password'] = is_string($input['password'] ?? null) ? $input['password'] : '';
                    $added = $this->imports->add($fields, $subject->userId);
                    $preflight = $added['preflight'];
                    if ($added['id'] !== null) {
                        $form = [];
                        $notice = 'operations.source_added';
                    } else {
                        $status = 422;
                    }
                } elseif ($action === 'remove_source') {
                    $source = $input['source'] ?? null;
                    if (!is_string($source) || preg_match('/^panel-[a-f0-9]{16}$/D', $source) !== 1) {
                        throw new RuntimeException('import_source_missing');
                    }
                    $this->imports->removeSource($source);
                    $notice = 'operations.source_removed';
                } elseif ($action === 'begin') {
                    if (!is_string($input['source'] ?? null)) {
                        throw new RuntimeException('import_source_missing');
                    }
                    $job = $this->imports->begin(
                        $input['source'],
                        $subject->userId,
                        ($input['acknowledged'] ?? null) === '1'
                    );
                } elseif ($action === 'publish') {
                    if ($job === null || ($input['confirm_publish'] ?? null) !== '1') {
                        throw new RuntimeException('import_confirmation_required');
                    }
                    $this->imports->publish($job, ($input['activate_members'] ?? null) === '1');
                    $notice = 'operations.import_published';
                } else {
                    $revision = $input['revision'] ?? null;
                    if ($job === null || !is_string($revision) || preg_match('/^[0-9]{1,18}$/D', $revision) !== 1) {
                        throw new RuntimeException('import_checkpoint_conflict');
                    }
                    $progress = $this->imports->run($job, (int) $revision);
                    if ($json) {
                        return $this->json($progress);
                    }
                }
                if ($action !== 'run' && $action !== 'step') {
                    $this->audit->record($subject->userId, 'admin.import_' . $action . '_completed');
                }
            }
            $sources = $this->imports->sources();
            $recent = $this->imports->recent();
            if ($job !== null) {
                $selected = $this->imports->job($job);
                $report = $this->imports->report($job, self::page($input['notes'] ?? null));
            }
        } catch (Throwable $failure) {
            $code = $failure->getMessage();
            if ($json && $job !== null && $code === 'import_checkpoint_conflict') {
                // Another tab ran the batch: carry on from the saved checkpoint.
                try {
                    $current = $this->imports->job($job);
                    return $this->json(['revision' => (int) $current['revision'],
                        'processed' => (int) $current['processed'], 'verified' => (int) $current['verified'],
                        'complete' => $current['state'] === 'completed']);
                } catch (Throwable) {
                    // Fall through to the localized error.
                }
            }
            $status = $code === 'import_denied' ? 403 : 422;
            $error = $this->translator->translate(self::message($code));
            if ($json) {
                return new Response(json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR), $status, [
                    'Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store',
                ]);
            }
        }
        if ($status !== 403) {
            try {
                $sources = $sources === [] ? $this->imports->sources() : $sources;
                $recent = $recent === [] ? $this->imports->recent() : $recent;
                if ($selected === null && $job !== null) {
                    $selected = $this->imports->job($job);
                    $report = $this->imports->report($job, self::page($input['notes'] ?? null));
                }
            } catch (Throwable) {
                // Keep the original localized error and expose no database or source details.
            }
        }
        if ($report !== null) {
            foreach ($report['notes'] as &$note) {
                $note['label'] = $this->translator->translate(self::note($note['reason']));
                $note['kind'] = $this->kind($note['entity']);
            }
            unset($note);
            $report['rows'] = [];
            foreach ($report['counts'] as $entity => $count) {
                $report['rows'][] = $count + ['label' => $this->kind((string) $entity)];
            }
            $report['estimates'] = $this->estimates($report['totals']);
            $base = ['job' => (string) ($selected['id'] ?? '')];
            $report['download'] = $this->urls->generate(CoreRoutes::ADMIN_IMPORTS, $base + ['format' => 'json']);
            $report['previous'] = $report['notes_page'] > 1 ? $this->urls->generate(
                CoreRoutes::ADMIN_IMPORTS,
                $base + ['notes' => $report['notes_page'] - 1]
            ) : null;
            $report['next'] = $report['notes_page'] < $report['notes_pages'] ? $this->urls->generate(
                CoreRoutes::ADMIN_IMPORTS,
                $base + ['notes' => $report['notes_page'] + 1]
            ) : null;
        }
        foreach ($recent as &$recentJob) {
            $recentJob['url'] = $this->urls->generate(CoreRoutes::ADMIN_IMPORTS, ['job' => $recentJob['id']]);
        }
        unset($recentJob);
        foreach ($sources as &$source) {
            $source['estimates'] = $this->estimates((array) ($source['preflight']['totals'] ?? []));
            $source['problems'] = array_map(
                fn(string $code): string => $this->translator->translate(self::message($code)),
                $source['preflight']['problems'] ?? []
            );
        }
        unset($source);
        if ($preflight !== null) {
            $preflight['messages'] = array_map(
                fn(string $code): array => ['text' => $this->translator->translate(self::message($code)),
                    'blocking' => in_array($code, ImportPreflight::BLOCKING, true)],
                $preflight['problems']
            );
        }
        return new Response($this->views->render('admin/imports.twig', [
            'title' => $this->translator->translate('operations.imports'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_IMPORTS),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_IMPORTS), 'sources' => $sources,
            'recent' => $recent, 'selected' => $selected, 'report' => $report, 'error' => $error,
            'job_url' => $selected === null ? null
                : $this->urls->generate(CoreRoutes::ADMIN_IMPORTS, ['job' => $selected['id']]),
            'notice' => $notice === null ? null : $this->translator->translate($notice), 'preflight' => $preflight,
            'form' => $form + ['host' => 'localhost', 'port' => '3306', 'charset' => 'UTF-8', 'engine' => ''],
            'engines' => ImportSources::ENGINES, 'charsets' => ImportSources::CHARSETS,
            'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer']);
    }

    private function json(array $progress): Response
    {
        return new Response(json_encode(['ok' => true, 'revision' => $progress['revision'],
            'processed' => $progress['processed'], 'verified' => $progress['verified'],
            'complete' => $progress['complete']], JSON_THROW_ON_ERROR), 200, [
            'Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store',
        ]);
    }

    private function download(string $job): Response
    {
        try {
            $export = $this->imports->export($job);
        } catch (Throwable) {
            return new Response('', 404, ['Cache-Control' => 'private, no-store']);
        }
        return new Response(
            json_encode($export, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'private, no-store',
                'Content-Disposition' => 'attachment; filename="nolviko-import-' . $job . '.json"',
                'X-Content-Type-Options' => 'nosniff']
        );
    }

    /** @return list<array{label: string, value: int}> */
    private function estimates(array $totals): array
    {
        $result = [];
        foreach ($totals as $kind => $value) {
            if (is_int($value)) {
                $result[] = ['label' => $this->kind((string) $kind), 'value' => $value];
            }
        }
        return $result;
    }

    private function kind(string $kind): string
    {
        return $this->translator->translate(match ($kind) {
            'users' => 'operations.kind_users',
            'groups' => 'operations.kind_groups',
            'categories' => 'operations.kind_categories',
            'forums' => 'operations.kind_forums',
            'threads' => 'operations.kind_threads',
            'posts' => 'operations.kind_posts',
            'attachments' => 'operations.kind_attachments',
            'polls' => 'operations.kind_polls',
            'poll_options' => 'operations.kind_poll_options',
            'poll_votes' => 'operations.kind_poll_votes',
            'private_sent' => 'operations.kind_private_sent',
            'private_inbox' => 'operations.kind_private_inbox',
            'private_files' => 'operations.kind_private_files',
            default => 'operations.kind_private',
        });
    }

    private static function page(mixed $value): int
    {
        return is_string($value) && preg_match('/^[1-9][0-9]{0,8}$/D', $value) === 1 ? (int) $value : 1;
    }

    private static function message(string $code): string
    {
        return match ($code) {
            'import_denied' => 'operations.denied',
            'import_identity_collision', 'import_existing_user_map_denied' => 'operations.collision',
            'import_confirmation_required' => 'operations.import_confirm_required',
            'import_attachment_root_required' => 'operations.source_attachments_required',
            'import_attachment_missing', 'import_attachment_invalid' => 'operations.import_files_needed',
            'import_checkpoint_conflict' => 'operations.import_reload',
            'import_parity_mismatch', 'import_count_mismatch', 'import_source_changed'
                => 'operations.import_changed',
            'import_source_fields_invalid' => 'operations.source_fields_invalid',
            'import_source_unavailable' => 'operations.source_unavailable',
            'import_source_not_found' => 'operations.source_not_found',
            'import_source_ambiguous' => 'operations.source_ambiguous',
            'import_source_version_unsupported' => 'operations.source_version_unsupported',
            'import_attachment_dir_missing' => 'operations.source_attachments_missing',
            'import_charset_differs' => 'operations.source_charset_differs',
            'import_source_writable' => 'operations.source_writable',
            'import_not_complete' => 'operations.import_not_complete',
            default => 'operations.import_failed',
        };
    }

    private static function note(string $reason): string
    {
        return match ($reason) {
            'password_reset_required' => 'operations.note_password',
            'existing_owner_explicitly_linked' => 'operations.note_linked',
            'recipient_deleted', 'system_identity' => 'operations.note_identity',
            'unowned_source_attachment' => 'operations.note_unowned',
            'orphan_file_owner_archive' => 'operations.note_orphan',
            'original_file_retained' => 'operations.note_file',
            default => 'operations.note_aggregate',
        };
    }
}
