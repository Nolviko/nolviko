<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Database\UpdatePreparation;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Http\TransportPolicy;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Updates\RecoveryPreparation;
use Nolviko\Core\Updates\SignedReleaseManifest;
use Nolviko\Core\Updates\UpdateStage;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Admin\Infrastructure\DbalUpdateOverview;
use Nolviko\Modules\Users\Http\RequestSubject;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Fresh administration actions; untrusted uploads cannot provide paths, publisher keys or safety assertions. */
final class UpdatesHandler implements RouteHandler
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly DbalUpdateOverview $overview,
        private readonly UrlGenerator $urls,
        private readonly AdminSession $admin,
        private readonly RequestSubject $subjects,
        private readonly CsrfTokens $csrf,
        private readonly RecoveryPreparation $preparation,
        private readonly ?UpdatePreparation $uploads = null,
        private readonly ?DbalAdminAudit $audit = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $actor = $this->subjects->userId($request);
        if ($actor === null || !$this->subjects->of($request)->allows(AdminGuard::PERMISSION)) {
            return new Response('', 403, ['Cache-Control' => 'no-store']);
        }
        $job = $this->overview->preparableJob();
        $prepared = null;
        $error = null;
        $notice = null;
        $configured = false;
        $status = Response::HTTP_OK;
        try {
            $configured = $this->uploads?->configured() ?? false;
            if ($request->isMethod('POST')) {
                $input = $request->request->all();
                $token = $input[CsrfTokens::FIELD] ?? null;
                $action = $input['action'] ?? null;
                if (
                    !TransportPolicy::allows($request) || !$this->admin->isFresh() || !is_string($token)
                    || !$this->csrf->isValid($token) || !is_string($action)
                    || !in_array($action, ['prepare_recovery', 'upload_release', 'download_release', 'stage_release',
                        'abandon_release', 'check_releases'], true)
                ) {
                    return $this->render(
                        $request,
                        $job,
                        null,
                        'admin.updates.prepare_denied',
                        null,
                        $configured,
                        Response::HTTP_FORBIDDEN
                    );
                }
                $this->audit?->record($actor, 'admin.update_' . $action . '_requested');
                if ($action === 'prepare_recovery') {
                    if ($job === null) {
                        throw new RuntimeException('update_not_found');
                    }
                    $shell = $this->views->render('admin/recovery.twig', [
                        'language' => str_replace('_', '-', $this->translator->locale()),
                        'stage_labels' => $this->labels(),
                    ]);
                    $prepared = $this->preparation->prepare(
                        $job,
                        $actor,
                        $request->getSchemeAndHttpHost(),
                        Request::getTrustedProxies(),
                        $shell
                    );
                    if ($prepared['grant'] !== null) {
                        $prepared['approved'] = $this->uploads?->publish($job, $actor) ?? false;
                    }
                } else {
                    $uploads = $this->uploads ?? throw new RuntimeException('update_trust_missing');
                    if ($action === 'upload_release') {
                        $archive = self::file($request, 'archive', SignedReleaseManifest::MAX_ARCHIVE_BYTES);
                        $body = (string) file_get_contents(self::file($request, 'manifest', 16384));
                        $signature = trim((string) file_get_contents(self::file($request, 'signature', 128)));
                        $job = $uploads->receive(
                            $archive,
                            $body,
                            $signature,
                            $actor,
                            ($input['confirmed'] ?? null) === '1'
                        );
                        $notice = $this->translator->translate('operations.uploaded');
                    } elseif ($action === 'download_release') {
                        // ADR-037: the newest signed release straight from the trusted repository.
                        $job = $uploads->download($actor, ($input['confirmed'] ?? null) === '1');
                        $notice = $this->translator->translate('operations.uploaded');
                    } elseif ($action === 'stage_release') {
                        $progress = $uploads->step($job ?? throw new RuntimeException('update_not_found'), $actor);
                        $notice = $this->translator->translate('operations.staging', ['count' => $progress['cursor']]);
                    } elseif ($action === 'abandon_release') {
                        $uploads->abandon($job ?? throw new RuntimeException('update_not_found'));
                        $job = null;
                        $notice = $this->translator->translate('operations.abandoned');
                    } else {
                        $version = $uploads->check();
                        $notice = $version === null ? $this->translator->translate('operations.no_release')
                            : $this->translator->translate('operations.release_hint', ['version' => $version]);
                    }
                }
                $this->audit?->record($actor, 'admin.update_' . $action . '_completed');
            }
        } catch (Throwable $failure) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            $error = match ($failure->getMessage()) {
                'update_trust_missing' => 'operations.setup',
                'update_inventory_missing', 'update_inventory_invalid' => 'operations.manual_baseline',
                'update_not_newer' => 'operations.not_newer',
                'update_confirmation_required' => 'operations.confirm_required',
                'update_disk_insufficient' => 'operations.disk',
                'update_addon_incompatible', 'update_addon_package_incompatible', 'update_theme_incompatible'
                    => 'operations.addons',
                'update_php_incompatible', 'update_php_extension_missing', 'update_database_incompatible'
                    => 'operations.platform',
                'update_integrity_required', 'update_writable_required', 'update_private_storage_required'
                    => 'operations.files',
                'update_quiescent_required', 'update_already_active' => 'operations.busy',
                'update_schema_required' => 'operations.schema',
                'update_upload_failed' => 'operations.upload_failed',
                'update_signed_assets_missing', 'update_discovery_unavailable', 'package_download_invalid',
                'package_download_failed', 'package_download_redirect', 'package_download_size'
                    => 'operations.download_failed',
                default => 'operations.refused',
            };
        }
        return $this->render($request, $job, $prepared, $error, $notice, $configured, $status);
    }

    private function render(
        Request $request,
        ?string $job,
        ?array $prepared,
        ?string $error,
        ?string $notice,
        bool $configured,
        int $status,
    ): Response {
        $runs = $this->overview->read();
        $html = $this->views->render('admin/updates.twig', [
            'title' => $this->translator->translate('admin.updates.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_UPDATES),
            'runs' => $runs, 'prepare_available' => $job !== null && $this->admin->isFresh(),
            'prepared' => $prepared, 'prepare_error' => $error, 'notice' => $notice, 'configured' => $configured,
            'has_job' => $job !== null,
            'recovery_url' => $this->urls->generate(CoreRoutes::ADMIN_RECOVERY_ENTRY),
            'post_url' => $this->urls->generate(CoreRoutes::ADMIN_UPDATES),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_token' => $this->csrf->token(),
        ]);
        return new Response($html, $runs === null ? Response::HTTP_SERVICE_UNAVAILABLE : $status, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function labels(): array
    {
        return [
            'discovered' => $this->translator->translate('admin.updates.stage_discovered'),
            'checked' => $this->translator->translate('admin.updates.stage_checked'),
            'downloaded' => $this->translator->translate('admin.updates.stage_downloaded'),
            'verified' => $this->translator->translate('admin.updates.stage_verified'),
            'staged' => $this->translator->translate('admin.updates.stage_staged'),
            'backed_up' => $this->translator->translate('admin.updates.stage_backed_up'),
            'maintenance' => $this->translator->translate('admin.updates.stage_maintenance'),
            'files_switched' => $this->translator->translate('admin.updates.stage_files_switched'),
            'migrations_applied' => $this->translator->translate('admin.updates.stage_migrations_applied'),
            'smoke_checked' => $this->translator->translate('admin.updates.stage_smoke_checked'),
            'completed' => $this->translator->translate('admin.updates.stage_completed'),
        ];
    }

    private static function file(Request $request, string $name, int $maximum): string
    {
        $file = $request->files->get($name);
        if (
            !$file instanceof UploadedFile || !$file->isValid() || $file->getSize() < 1
            || $file->getSize() > $maximum || is_link($file->getPathname())
        ) {
            throw new RuntimeException('update_upload_failed');
        }
        return $file->getPathname();
    }
}
