<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Database\ExtensionMigrations;
use Nolviko\Core\Extensions\ExtensionArchive;
use Nolviko\Core\Extensions\ExtensionFailure;
use Nolviko\Core\Extensions\ExtensionManager;
use Nolviko\Core\Extensions\ExtensionManifest;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Updates\PackageAssetDownloader;
use Nolviko\Core\Updates\PackageUpdates;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Trusted-code lifecycle UI, with core authorization, fresh administrator authentication and CSRF. */
final readonly class ExtensionsHandler implements RouteHandler
{
    public function __construct(
        private ExtensionManager $manager,
        private ExtensionMigrations $migrations,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private TemplateRenderer $views,
        private MessageTranslator $translator,
        private UrlGenerator $urls,
        private DbalAdminAudit $audit,
        private array $settingsPages = [],
        private ?PackageUpdates $updates = null,
        private ?PackageAssetDownloader $downloader = null,
    ) {
    }

    /**
     * One-step install of a package file (upload or panel update): stage, switch an enabled older version off,
     * install, enable with its migrations. Returns the extension ID.
     */
    private function installPackage(ExtensionArchive $archive, string $path, bool $enable = true): string
    {
        $token = $archive->stage($path);
        $id = $archive->stagedId($token);
        if (isset($this->manager->state->enabled()[$id])) {
            $this->manager->disable($id);
        }

        $archive->install($token);
        // Beta.10: a package installed from the official catalog is not switched on by itself; turning a new
        // extension on stays the administrator's own decision.
        if ($enable) {
            $this->manager->enable($id, $this->migrations->apply(...));
        }

        return $id;
    }

    /** Name and description message IDs of the official extensions (literal, for the i18n contract). */
    private const CATALOG_TEXTS = [
        'nolviko/totp' => ['admin.extensions.catalog_name_totp', 'admin.extensions.catalog_about_totp'],
        'nolviko/google' => ['admin.extensions.catalog_name_google', 'admin.extensions.catalog_about_google'],
        'nolviko/seo' => ['admin.extensions.catalog_name_seo', 'admin.extensions.catalog_about_seo'],
        'nolviko/importer' => ['admin.extensions.catalog_name_importer', 'admin.extensions.catalog_about_importer'],
        'nolviko/analytics' => ['admin.extensions.catalog_name_analytics', 'admin.extensions.catalog_about_analytics'],
        'nolviko/google_analytics' => ['admin.extensions.catalog_name_google_analytics',
            'admin.extensions.catalog_about_google_analytics'],
        'nolviko/ads' => ['admin.extensions.catalog_name_ads', 'admin.extensions.catalog_about_ads'],
        'nolviko/captcha' => ['admin.extensions.catalog_name_captcha', 'admin.extensions.catalog_about_captcha'],
        'nolviko/menu' => ['admin.extensions.catalog_name_menu', 'admin.extensions.catalog_about_menu'],
        'nolviko/pages' => ['admin.extensions.catalog_name_pages', 'admin.extensions.catalog_about_pages'],
    ];

    /**
     * Official extensions that are not installed yet, with their newest release (beta.10 catalog).
     *
     * @param array<string, mixed> $installed Extension ID => manifest.
     * @return list<array{id: string, name: string, about: string, version: string, repository: string, tag: string}>
     */
    private function officialCatalog(array $installed): array
    {
        $rows = [];
        foreach (PackageUpdates::OFFICIAL_EXTENSIONS as $id => $repository) {
            $release = isset($installed[$id]) || $this->updates === null ? null
                : $this->updates->available($repository, '0.0.0', PackageUpdates::extensionAsset($id));
            if ($release !== null) {
                [$name, $about] = self::CATALOG_TEXTS[$id] ?? self::CATALOG_TEXTS['nolviko/seo'];
                $rows[] = ['id' => $id, 'name' => $this->translator->translate($name),
                    'about' => $this->translator->translate($about),
                    'version' => $release['version'], 'repository' => $repository, 'tag' => $release['tag']];
            }
        }

        return $rows;
    }

    /** @return array{repository: string, version: string, tag: string, asset: string, bytes: int}|null */
    private function updateFor(ExtensionManifest $manifest): ?array
    {
        $repository = PackageUpdates::extensionRepository($manifest->id, $manifest->data['repository'] ?? null);

        return $this->updates === null || !is_string($repository) ? null : $this->updates->available(
            $repository,
            $manifest->version,
            PackageUpdates::extensionAsset($manifest->id),
        );
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        if ($subject->userId === null || !$subject->allows('admin.access')) {
            return new Response('', 403, ['Cache-Control' => 'no-store, private']);
        }

        $status = 200;
        $error = null;
        $installed = null;
        $rows = [];
        $diagnostics = [];
        try {
            if ($request->isMethod('POST')) {
                $input = $request->request->all();
                $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
                if (!$this->admin->isFresh() || !$this->csrf->isValid($text(CsrfTokens::FIELD))) {
                    return new Response('', 403, ['Cache-Control' => 'no-store, private']);
                }

                $action = $text('action');
                if (
                    !in_array($action, ['enable', 'disable', 'safe', 'resume', 'upload', 'update', 'rollback',
                        'disable_all', 'install_official', 'remove'], true)
                ) {
                    throw new ExtensionFailure('extension_action_invalid');
                }

                if ($text('confirm') !== '1') {
                    throw new ExtensionFailure('extension_confirmation_required');
                }

                $this->audit->record($subject->userId, 'admin.extension_' . $action . '_requested');
                $archive = new ExtensionArchive($this->manager);
                switch ($action) {
                    case 'enable':
                        $this->manager->enable($text('id'), $this->migrations->apply(...));
                        break;
                    case 'disable_all':
                        $this->manager->disableAll();
                        break;
                    case 'disable':
                        $this->manager->disable($text('id'));
                        break;
                    case 'safe':
                        $this->manager->state->setSafe(true);
                        break;
                    case 'resume':
                        $this->manager->resume();
                        break;
                    case 'upload':
                        // One step (owner request 2026-11-17): upload, replace an older version, enable. An update
                        // still swaps files only while the extension is off, as the core rule requires.
                        $upload = $request->files->get('package');
                        if (!$upload instanceof UploadedFile || !$upload->isValid()) {
                            throw new ExtensionFailure('extension_package_invalid');
                        }

                        $id = $this->installPackage($archive, $upload->getPathname());

                        return InstallerPage::redirect(
                            $this->urls->generate(CoreRoutes::ADMIN_EXTENSIONS) . '?installed=' . rawurlencode($id),
                        );
                    case 'update':
                        // Panel update (2026-11-17): the newer release of the package's own repository, downloaded
                        // from GitHub only after the administrator confirmed it.
                        $manifest = $this->manager->catalog->discover()['manifests'][$text('id')] ?? null;
                        $update = $manifest === null ? null : $this->updateFor($manifest);
                        $repository = $update === null ? null : PackageUpdates::repository($update['repository']);
                        if ($update === null || $repository === null || $this->downloader === null) {
                            throw new ExtensionFailure('extension_update_unavailable');
                        }
                        try {
                            $file = $this->downloader->download(
                                $repository,
                                $update['tag'],
                                $update['asset'],
                                $update['bytes'],
                                4194304,
                            );
                        } catch (Throwable) {
                            throw new ExtensionFailure('extension_update_download_failed');
                        }
                        try {
                            $id = $this->installPackage($archive, $file);
                        } finally {
                            @unlink($file);
                        }

                        return InstallerPage::redirect(
                            $this->urls->generate(CoreRoutes::ADMIN_EXTENSIONS) . '?installed=' . rawurlencode($id),
                        );
                    case 'install_official':
                        // Official catalog (beta.10): download the newest release of an official extension that is
                        // not installed yet, verify and install it; it is enabled with its own button.
                        $id = $text('id');
                        $installedNow = $this->manager->catalog->discover()['manifests'];
                        $spec = PackageUpdates::OFFICIAL_EXTENSIONS[$id] ?? null;
                        $release = $spec === null || isset($installedNow[$id]) || $this->updates === null ? null
                            : $this->updates->available($spec, '0.0.0', PackageUpdates::extensionAsset($id));
                        $repository = $release === null ? null : PackageUpdates::repository($release['repository']);
                        if ($release === null || $repository === null || $this->downloader === null) {
                            throw new ExtensionFailure('extension_update_unavailable');
                        }
                        try {
                            $file = $this->downloader->download(
                                $repository,
                                $release['tag'],
                                $release['asset'],
                                $release['bytes'],
                                4194304,
                            );
                        } catch (Throwable) {
                            throw new ExtensionFailure('extension_update_download_failed');
                        }
                        try {
                            $this->installPackage($archive, $file, false);
                        } finally {
                            @unlink($file);
                        }
                        break;
                    case 'remove':
                        // ADR-038 (2026-11-20): a disabled extension's files go; rows its manifest lists under
                        // remove.clear (credentials) are deleted; everything else it created stays.
                        $archive->remove($text('id'), $this->migrations->clear(...));
                        break;
                    case 'rollback':
                        // Restore the kept older copy (2026-11-17): off, swap back, on again with its migrations
                        // (expand-only, so the older code runs on the newer schema).
                        $id = null;
                        foreach ($archive->previousVersions() as $owner => $copy) {
                            $id = $copy['token'] === $text('token') ? $owner : $id;
                        }
                        if ($id === null) {
                            throw new ExtensionFailure('extension_package_invalid');
                        }
                        if (isset($this->manager->state->enabled()[$id])) {
                            $this->manager->disable($id);
                        }

                        $archive->rollback($text('token'));
                        $this->manager->enable($id, $this->migrations->apply(...));
                        break;
                }

                return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_EXTENSIONS));
            }

            $enabled = $this->manager->state->enabled();
            $catalog = $this->manager->catalog->discover();
            $kept = (new ExtensionArchive($this->manager))->previousVersions();
            $just = $request->query->get('installed');
            foreach ($catalog['manifests'] as $manifest) {
                // An enabled extension's own settings page, when it registered one (2026-11-15).
                $settings = isset($enabled[$manifest->id], $this->settingsPages[$manifest->id])
                    ? $this->urls->generate($this->settingsPages[$manifest->id]) : null;
                if ($just === $manifest->id) {
                    $installed = ['name' => $manifest->data['name'], 'version' => $manifest->version,
                        'settings_url' => $settings];
                }
                $rows[] = ['id' => $manifest->id, 'name' => $manifest->data['name'], 'version' => $manifest->version,
                    'enabled' => isset($enabled[$manifest->id]), 'settings_url' => $settings,
                    'previous' => $kept[$manifest->id] ?? null, 'update' => $this->updateFor($manifest)];
            }

            $official = $this->officialCatalog($catalog['manifests']);
            $diagnostics = $this->manager->diagnostics();
        } catch (ExtensionFailure $failure) {
            $status = 422;
            $panelUpdate = in_array(
                $failure->getMessage(),
                ['extension_update_unavailable', 'extension_update_download_failed'],
                true,
            );
            $error = $this->translator->translate(
                $panelUpdate ? 'admin.updates.package_failed' : 'admin.extensions.invalid',
            );
            $diagnostics = ['operation' => strtok($failure->getMessage(), ':')];
        } catch (Throwable) {
            $status = 503;
            $error = $this->translator->translate('admin.extensions.unavailable');
        }

        return new Response($this->views->render('admin/extensions.twig', [
            'title' => $this->translator->translate('admin.extensions.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_EXTENSIONS),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_EXTENSIONS),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
            'fresh' => $this->admin->isFresh(), 'safe' => $this->manager->state->safe(),
            'extensions' => $rows, 'diagnostics' => $diagnostics, 'error' => $error, 'installed' => $installed,
            'official' => $official ?? [],
            'actions' => ['safe' => 'safe', 'resume' => 'resume', 'disable_all' => 'disable_all',
                'enable' => 'enable', 'disable' => 'disable', 'upload' => 'upload', 'update' => 'update',
                'rollback' => 'rollback', 'install_official' => 'install_official', 'remove' => 'remove'],
        ]), $status, ['Cache-Control' => 'no-store, private']);
    }
}
