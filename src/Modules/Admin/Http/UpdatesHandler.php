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
use Nolviko\Core\Updates\UpdatePreflightFailed;
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
    /**
     * One-click updates (owner request 2026-11-17, "like WordPress"): admin-update.js drives the same server steps
     * one after another; each request keeps working for about this long, well inside a 30 s PHP limit.
     */
    private const STEP_SECONDS = 12.0;

    /** Steps that can break the site: they need the password from the popup (owner decision 2026-11-18). */
    private const CRITICAL = ['download_release', 'upload_release', 'stage_release', 'prepare_recovery'];

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
        $wantsJson = $request->headers->get('X-Nolviko-Update') === '1';
        $progress = null;
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
                    if ($wantsJson) {
                        $denied = ['admin.updates.prepare_denied'];
                        return $this->json($job, null, null, Response::HTTP_FORBIDDEN, $denied, []);
                    }
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
                if (in_array($action, self::CRITICAL, true) && !$this->admin->isConfirmed()) {
                    if ($wantsJson) {
                        return new Response(json_encode(['ok' => false, 'password' => true, 'problems' => [],
                            'codes' => []], JSON_THROW_ON_ERROR), Response::HTTP_FORBIDDEN, [
                            'Content-Type' => 'application/json; charset=UTF-8',
                            'Cache-Control' => 'no-store',
                        ]);
                    }
                    return $this->render(
                        $request,
                        $job,
                        null,
                        'admin.updates.confirm_first',
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
                    $deadline = microtime(true) + self::STEP_SECONDS;
                    do {
                        $prepared = $this->preparation->prepare(
                            $job,
                            $actor,
                            $request->getSchemeAndHttpHost(),
                            Request::getTrustedProxies(),
                            $shell
                        );
                    } while ($prepared['grant'] === null && microtime(true) < $deadline);
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
                        $deadline = microtime(true) + self::STEP_SECONDS;
                        do {
                            $progress = $uploads->step($job ?? throw new RuntimeException('update_not_found'), $actor);
                        } while (!$progress['ready'] && microtime(true) < $deadline);
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
            // Every failed check with its own explanation (2026-11-17, owner request), not one summary line.
            $issues = $failure instanceof UpdatePreflightFailed ? $failure->issues : [$failure->getMessage()];
            $details = array_values(array_unique(array_map(self::message(...), $issues)));
            $error = $details[0];
            // Issues without their own explanation still show their code, so the owner can report or look it up.
            $codes = array_values(array_filter(
                array_map(strval(...), $issues),
                static fn(string $issue): bool => self::message($issue) === 'operations.refused'
                    && preg_match('/^[a-z0-9_]{1,64}$/D', $issue) === 1,
            ));
        }
        if ($wantsJson) {
            return $this->json($job, $progress, $prepared, $status, $details ?? [], $codes ?? [], $issues ?? []);
        }
        return $this->render(
            $request,
            $job,
            $prepared,
            $error,
            $notice,
            $configured,
            $status,
            $details ?? [],
            $codes ?? [],
        );
    }

    /**
     * The one-click driver's answer. The recovery grant goes back only to this fresh, CSRF-checked administrator,
     * exactly as the page used to show it for copying.
     *
     * @param array{ready: bool, cursor: int}|null $progress
     * @param array<string, mixed>|null $prepared
     * @param list<string> $details
     * @param list<string> $codes
     */
    private function json(
        ?string $job,
        ?array $progress,
        ?array $prepared,
        int $status,
        array $details,
        array $codes,
        array $issues = [],
    ): Response {
        $issues = array_values(array_filter(
            array_map(strval(...), $issues),
            static fn(string $issue): bool => preg_match('/^[a-z0-9_]{1,64}$/D', $issue) === 1,
        ));
        $grant = is_array($prepared['grant'] ?? null)
            ? $prepared['grant']['id'] . '.' . $prepared['grant']['code'] . '.' . $prepared['grant']['nonce'] : null;
        $refused = $grant !== null && ($prepared['approved'] ?? false) !== true;
        if ($refused) {
            // The safety copy is ready but the release could not be approved for recovery: say so (beta.10).
            $details[] = 'operations.refused';
            $codes[] = 'update_recovery_not_approved';
        }
        $data = [
            'ok' => $status < 400,
            'job' => $job !== null,
            'ready' => $progress['ready'] ?? false,
            'cursor' => $progress['cursor'] ?? ($prepared['progress']['copied'] ?? 0),
            'grant' => $grant !== null && ($prepared['approved'] ?? false) === true ? $grant : null,
            // The safety copy is finished but the release was not approved for recovery: stop, do not loop.
            'refused' => $refused,
            'issues' => $issues,
            'problems' => array_map(fn(string $key): string => $this->translator->translate($key), $details),
            'codes' => $codes,
        ];

        return new Response(json_encode($data, JSON_THROW_ON_ERROR), $status, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** The translation key that explains one update issue and what to do about it. */
    private static function message(string $issue): string
    {
        return match ($issue) {
            'update_trust_missing' => 'operations.setup',
            'update_inventory_missing', 'update_inventory_invalid' => 'operations.manual_baseline',
            'update_not_newer' => 'operations.not_newer',
            'update_confirmation_required' => 'operations.confirm_required',
            'update_disk_insufficient' => 'operations.disk',
            'update_addon_incompatible', 'update_addon_package_incompatible', 'update_theme_incompatible'
                => 'operations.addons',
            'update_php_incompatible', 'update_php_extension_missing', 'update_database_incompatible'
                => 'operations.platform',
            'update_integrity_required' => 'operations.integrity',
            'update_writable_required' => 'operations.writable',
            'update_private_storage_required' => 'operations.private_storage',
            'update_storage_invalid' => 'operations.storage_permissions',
            'update_backup_required' => 'operations.backup_unavailable',
            'update_channel_mismatch' => 'operations.channel',
            'update_quiescent_required', 'update_already_active', 'update_begin_busy' => 'operations.busy',
            'update_manifest_replayed' => 'operations.replayed',
            'update_manifest_time_invalid' => 'operations.clock',
            'update_schema_required' => 'operations.schema',
            'update_upload_failed' => 'operations.upload_failed',
            'update_signed_assets_missing', 'update_discovery_unavailable', 'package_download_invalid',
            'package_download_failed', 'package_download_redirect', 'package_download_size'
                => 'operations.download_failed',
            default => 'operations.refused',
        };
    }

    private function render(
        Request $request,
        ?string $job,
        ?array $prepared,
        ?string $error,
        ?string $notice,
        bool $configured,
        int $status,
        array $details = [],
        array $codes = [],
    ): Response {
        $runs = $this->overview->read();
        $html = $this->views->render('admin/updates.twig', [
            'title' => $this->translator->translate('admin.updates.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_UPDATES),
            'runs' => $runs, 'prepare_available' => $job !== null && $this->admin->isConfirmed(),
            'prepared' => $prepared, 'prepare_error' => $error, 'notice' => $notice, 'configured' => $configured,
            'has_job' => $job !== null, 'problems' => $details, 'problem_codes' => $codes,
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
