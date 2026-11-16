<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\TemplateException;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Core\View\ThemeArchive;
use Nolviko\Core\View\ThemeBuildStore;
use Nolviko\Core\View\ThemeCatalog;
use Nolviko\Core\View\ThemePresentation;
use Nolviko\Core\View\ThemeTokens;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Fresh-admin, CSRF-protected theme workflow; imports are trusted source packages, never executable PHP. */
final readonly class ThemesHandler implements RouteHandler
{
    public function __construct(
        private ThemeBuildStore $builds,
        private ThemePresentation $presentation,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private Session $session,
        private TemplateRenderer $views,
        private MessageTranslator $translator,
        private UrlGenerator $urls,
        private ThemeArchive $archive,
        private DbalAdminAudit $audit
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $owner = $subject->userId ?? 0;
        $error = null;
        $status = 200;
        $draft = null;
        $themes = [];
        $warnings = [];
        $tokensInput = null;
        $selectedTheme = null;
        $notice = $this->session->get('_theme_notice:' . $owner);
        $this->session->remove('_theme_notice:' . $owner);
        try {
            if ($owner < 1 || !$subject->allows('admin.access')) {
                $status = 403;
                throw new TemplateException('theme_admin_denied');
            }
            $themes = (new ThemeCatalog($this->builds->root . '/themes'))->all();
            $draft = $this->builds->draft($owner);
            if ($draft !== null) {
                $warnings = $this->builds->get($draft['build'])['warnings'];
            }
            if ($request->isMethod('POST')) {
                $input = $request->request->all();
                $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
                $tokensInput = is_string($input['tokens'] ?? null) && strlen($text('tokens')) <= 65536
                    && mb_check_encoding($text('tokens'), 'UTF-8')
                    ? $text('tokens') : null;
                $selectedTheme = $text('theme') === '' ? null : mb_substr($text('theme'), 0, 129);
                if (!$this->admin->isFresh() || !$this->csrf->isValid($text(CsrfTokens::FIELD))) {
                    $status = 403;
                    throw new TemplateException('theme_admin_denied');
                }
                $action = $text('action');
                if (!in_array($action, ['draft', 'preview', 'stop', 'publish', 'import', 'export', 'rollback'], true)) {
                    throw new TemplateException('theme_action_invalid');
                }
                $this->audit->record($owner, 'admin.theme_' . $action . '_requested');
                switch ($text('action')) {
                    case 'draft':
                        $json = $text('tokens');
                        $tokens = strlen($json) <= 65536 ? json_decode($json, true, 16) : null;
                        if (!is_array($tokens)) {
                            throw new TemplateException('theme_tokens_invalid');
                        }
                        $this->builds->build($text('theme'), $tokens, $owner);
                        break;
                    case 'preview':
                        $this->presentation->preview($owner, $text('build'));
                        return InstallerPage::redirect($this->urls->generate(CoreRoutes::HOME));
                    case 'stop':
                        $this->presentation->stop();
                        break;
                    case 'publish':
                        if ($text('confirm') !== '1') {
                            throw new TemplateException('theme_action_invalid');
                        }
                        $this->builds->publish($owner, $text('build'));
                        $this->presentation->stop();
                        break;
                    case 'rollback':
                        if ($text('confirm') !== '1') {
                            throw new TemplateException('theme_action_invalid');
                        }
                        $this->builds->rollback($text('build'));
                        $this->presentation->stop();
                        break;
                    case 'import':
                        if ($text('trusted') !== '1') {
                            throw new TemplateException('theme_package_invalid');
                        }
                        $upload = $request->files->get('package');
                        if (!$upload instanceof UploadedFile || !$upload->isValid()) {
                            throw new TemplateException('theme_package_invalid');
                        }
                        $this->archive->import($upload->getPathname());
                        break;
                    case 'export':
                        return new Response($this->archive->export($text('theme')), 200, [
                            'Content-Type' => 'application/zip',
                                'Content-Disposition' => 'attachment; filename="theme.zip"',
                            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
                        ]);
                    default:
                        throw new TemplateException('theme_action_invalid');
                }
                $this->session->set('_theme_notice:' . $owner, true);
                return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_THEMES));
            }
        } catch (TemplateException $failure) {
            if ($status === 200) {
                $status = 422;
            }
            $error = $this->translator->translate(match (strtok($failure->getMessage(), ':')) {
                'theme_contrast_failed' => 'admin.themes.contrast',
                'theme_draft_stale' => 'admin.themes.stale',
                'theme_publish_incompatible' => 'admin.themes.incompatible',
                'theme_identity_exists' => 'admin.themes.identity_exists',
                default => 'admin.themes.invalid',
            });
        } catch (Throwable) {
            $status = 503;
            $error = $this->translator->translate('admin.themes.unavailable');
        }
        return new Response($this->views->render('admin/themes.twig', [
            'title' => $this->translator->translate('admin.themes.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_THEMES),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_THEMES),
            'active_build' => $status === 200 && $this->builds->hasPrevious() ? $this->builds->pointer() : null,
            'themes' => $themes, 'draft' => $draft, 'warnings' => $warnings, 'notice' => $notice === true,
            'tokens' => $tokensInput ?? json_encode(
                $draft['tokens'] ?? ThemeTokens::DEFAULTS,
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ),
            'selected_theme' => $selectedTheme ?? $draft['theme'] ?? 'default',
            'error' => $error, 'fresh' => $this->admin->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
            'actions' => ['draft' => 'draft', 'preview' => 'preview', 'stop' => 'stop', 'publish' => 'publish',
                'import' => 'import', 'export' => 'export', 'rollback' => 'rollback'],
        ]), $status, ['Cache-Control' => 'no-store, private']);
    }
}
