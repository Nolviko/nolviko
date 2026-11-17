<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Database\ExtensionMigrations;
use Nolviko\Core\Extensions\ExtensionArchive;
use Nolviko\Core\Extensions\ExtensionFailure;
use Nolviko\Core\Extensions\ExtensionManager;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
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
    ) {
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
                    !in_array($action, ['enable', 'disable', 'safe', 'resume', 'upload', 'rollback',
                        'disable_all'], true)
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

                        $token = $archive->stage($upload->getPathname());
                        $id = $archive->stagedId($token);
                        if (isset($this->manager->state->enabled()[$id])) {
                            $this->manager->disable($id);
                        }

                        $archive->install($token);
                        $this->manager->enable($id, $this->migrations->apply(...));

                        return InstallerPage::redirect(
                            $this->urls->generate(CoreRoutes::ADMIN_EXTENSIONS) . '?installed=' . rawurlencode($id),
                        );
                    case 'rollback':
                        $archive->rollback($text('token'));
                        break;
                }

                return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_EXTENSIONS));
            }

            $enabled = $this->manager->state->enabled();
            $catalog = $this->manager->catalog->discover();
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
                    'enabled' => isset($enabled[$manifest->id]), 'settings_url' => $settings];
            }

            $diagnostics = $this->manager->diagnostics();
        } catch (ExtensionFailure $failure) {
            $status = 422;
            $error = $this->translator->translate('admin.extensions.invalid');
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
            'actions' => ['safe' => 'safe', 'resume' => 'resume', 'disable_all' => 'disable_all',
                'enable' => 'enable', 'disable' => 'disable', 'upload' => 'upload', 'rollback' => 'rollback'],
        ]), $status, ['Cache-Control' => 'no-store, private']);
    }
}
