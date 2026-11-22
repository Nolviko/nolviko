<?php

declare(strict_types=1);

namespace Nolviko\Modules\Content\Http;

use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminNavigation;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Content\Domain\ContentRejected;
use Nolviko\Modules\Content\Infrastructure\ContentLibrary;
use Nolviko\Modules\Content\Infrastructure\SiteImages;
use Nolviko\Modules\Editor\Domain\CustomTag;
use Nolviko\Modules\Editor\Domain\CustomTagRejected;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/admin/smileys`, `/admin/icons` and `/admin/mycode` (beta.18, ADR-042). Every change needs a fresh administrator
 * session and CSRF (kernel guard); ContentLibrary validates, audits and bumps the add-on version.
 */
final readonly class ContentAdminHandler implements RouteHandler
{
    private const SECTIONS = [CoreRoutes::ADMIN_SMILEYS => 'smileys', CoreRoutes::ADMIN_ICONS => 'icons',
        CoreRoutes::ADMIN_MYCODE => 'mycode'];

    public function __construct(
        private ContentLibrary $library,
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
        $route = (string) ($parameters['_route'] ?? CoreRoutes::ADMIN_SMILEYS);
        $section = self::SECTIONS[$route] ?? 'smileys';
        $subject = $this->subjects->of($request);
        $flash = '_content_notice:' . ($subject->userId ?? 0);
        $notice = $this->session->get($flash);
        $this->session->remove($flash);
        $error = null;
        $status = 200;

        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
            $id = (int) (ctype_digit($text('id')) ? $text('id') : '0');

            if (
                !$this->csrf->isValid($text(CsrfTokens::FIELD)) || !$this->admin->isFresh()
                || $subject->userId === null || !$subject->allows('admin.access')
            ) {
                [$status, $error] = [403, $this->translator->translate('admin.site.denied')];
            } else {
                try {
                    $actor = $subject->userId;
                    match ($text('action')) {
                        'add_smiley' => $this->library->addSmiley(
                            $text('code'),
                            $text('aliases'),
                            $text('title'),
                            self::bytes($request),
                            $actor,
                        ),
                        'update_smiley' => $this->library->updateSmiley(
                            $id,
                            $text('code'),
                            $text('aliases'),
                            $text('title'),
                            $actor,
                        ),
                        'delete_smiley' => $this->library->deleteSmiley($id, $actor),
                        'add_icon' => $this->library->addIcon($text('title'), self::bytes($request), $actor),
                        'delete_icon' => $this->library->deleteIcon($id, $actor),
                        'save_mycode' => $this->library->saveBbcode(
                            $id > 0 ? $id : null,
                            $text('pattern'),
                            $text('parameter'),
                            $text('template'),
                            $text('description'),
                            $text('enabled') === '1',
                            $actor,
                        ),
                        'delete_mycode' => $this->library->deleteBbcode($id, $actor),
                        default => throw new ContentRejected('content_invalid'),
                    };
                    $this->session->set($flash, $this->translator->translate('content.admin.saved'));

                    return InstallerPage::redirect($this->urls->generate($route));
                } catch (ContentRejected | CustomTagRejected $rejected) {
                    [$status, $error] = [422, $this->translator->translate(
                        'diagnostics.' . $rejected->diagnostic,
                        $rejected->parameters,
                    )];
                }
            }
        }

        return new Response($this->views->render('admin/content.twig', [
            'title' => $this->translator->translate('content.admin.' . $section),
            'nav' => AdminNavigation::items($this->urls, $this->translator, $route),
            'section' => $section,
            'action' => $this->urls->generate($route),
            'smileys' => $section === 'smileys' ? $this->library->smileys() : [],
            'icons' => $section === 'icons' ? array_values($this->library->icons()) : [],
            'mycodes' => $section === 'mycode' ? $this->library->bbcodes() : [],
            'parameters' => CustomTag::PARAMETERS,
            'elements' => implode(', ', array_keys(CustomTag::ELEMENTS)),
            'max_kb' => intdiv(SiteImages::MAX_BYTES, 1024),
            'max_side' => SiteImages::MAX_SIDE,
            'error' => $error,
            'notice' => is_string($notice) ? $notice : null,
            'fresh' => $this->admin->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    private static function bytes(Request $request): string
    {
        $file = $request->files->get('image');

        if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() > SiteImages::MAX_BYTES) {
            throw new ContentRejected('content_image_invalid', ['kb' => (string) intdiv(SiteImages::MAX_BYTES, 1024)]);
        }

        $bytes = file_get_contents($file->getPathname(), false, null, 0, SiteImages::MAX_BYTES + 1);

        return is_string($bytes) ? $bytes : '';
    }
}
