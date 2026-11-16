<?php

declare(strict_types=1);

namespace Nolviko\Modules\Gallery\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\Settings\SiteSettings;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Gallery\Domain\GalleryRejected;
use Nolviko\Modules\Gallery\Domain\GalleryRules;
use Nolviko\Modules\Gallery\Domain\GalleryViewer;
use Nolviko\Modules\Gallery\Infrastructure\Gallery;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Media gallery pages (2026-11-08): `/gallery` (newest images and albums; `?user=` one member's albums),
 * `/gallery/albums/new`, `/gallery/albums/{album}` (images; the owner uploads, edits and deletes),
 * `/gallery/images/{item}` (one image) with its file at `/gallery/images/{item}/{variant}`, and `/gallery/pending`
 * (approval queue). Every rule lives in Gallery; this class only reads input and renders.
 */
final readonly class GalleryHandler implements RouteHandler
{
    private const NOTICE = '_gallery_notice';

    public function __construct(
        private Gallery $gallery,
        private SiteSettings $settings,
        private RequestSubject $subjects,
        private CsrfTokens $csrf,
        private Session $session,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
        private ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $route = (string) ($parameters['_route'] ?? '');
        $viewer = GalleryViewer::of($this->subjects->of($request));
        $rules = GalleryRules::fromSettings($this->settings->values());

        // JSON answers, also when the gallery is off (Gallery::upload refuses then).
        if ($route === CoreRoutes::GALLERY_ATTACH) {
            return $this->attach($request, $viewer, GalleryRules::forPosts($this->settings->values()));
        }

        // Images stay visible in posts and albums whatever the switches say now; only the pages and uploads stop.
        if (!$rules->enabled && !$viewer->admin && $route !== CoreRoutes::GALLERY_FILE) {
            return $this->errors->notFound();
        }

        try {
            return match ($route) {
                CoreRoutes::GALLERY_FILE => $this->gallery->file(
                    $viewer,
                    (int) ($parameters['item'] ?? 0),
                    ($parameters['variant'] ?? '') === 'thumb',
                ),
                CoreRoutes::GALLERY_ITEM => $this->itemPage($request, $viewer, (int) ($parameters['item'] ?? 0)),
                CoreRoutes::GALLERY_ALBUM_NEW => $this->albumForm($request, $viewer),
                CoreRoutes::GALLERY_ALBUM => $this->albumPage(
                    $request,
                    $viewer,
                    $rules,
                    (int) ($parameters['album'] ?? 0),
                ),
                CoreRoutes::GALLERY_PENDING => $this->pending($request, $viewer),
                default => $this->index($request, $viewer),
            };
        } catch (GalleryRejected $rejected) {
            return $rejected->diagnostic === 'gallery_not_found'
                ? $this->errors->notFound() : $this->errors->forbidden();
        }
    }

    private function index(Request $request, GalleryViewer $viewer): Response
    {
        $owner = $request->query->getInt('user') ?: null;
        $albums = $this->gallery->albums($viewer, $owner, $request->query->getInt('before'));
        $base = $this->urls->generate(CoreRoutes::GALLERY);
        $more = count($albums) > Gallery::PAGE ? $base . '?' . http_build_query(array_filter([
            'user' => $owner, 'before' => $albums[Gallery::PAGE - 1]['id']])) : null;
        $title = $this->translator->translate('gallery.title');

        return $this->render('gallery/index.twig', [
            'title' => $title,
            'images' => $owner === null ? array_slice($this->gallery->items($viewer, null), 0, 24) : [],
            'albums' => array_slice($albums, 0, Gallery::PAGE),
            'more' => $more,
            'mine_url' => $viewer->id === null ? null : $base . '?user=' . $viewer->id,
            'new_url' => $viewer->id === null ? null : $this->urls->generate(CoreRoutes::GALLERY_ALBUM_NEW),
            'pending_url' => $viewer->admin || $viewer->moderator
                ? $this->urls->generate(CoreRoutes::GALLERY_PENDING) : null,
            'filtered' => $owner !== null,
        ]);
    }

    private function albumForm(Request $request, GalleryViewer $viewer): Response
    {
        if ($viewer->id === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $input = self::input($request);
        $error = null;

        if ($request->isMethod('POST')) {
            try {
                $id = $this->gallery->saveAlbum(
                    $viewer,
                    null,
                    $input('title'),
                    $input('description'),
                    $input('visibility'),
                );

                return InstallerPage::redirect(
                    $this->urls->generate(CoreRoutes::GALLERY_ALBUM, ['album' => (string) $id]),
                );
            } catch (GalleryRejected $rejected) {
                $error = $this->translator->translate('diagnostics.' . $rejected->diagnostic, $rejected->parameters);
            }
        }

        return $this->render('gallery/album_form.twig', [
            'title' => $this->translator->translate('gallery.new_album'),
            'action' => $this->urls->generate(CoreRoutes::GALLERY_ALBUM_NEW),
            'back_url' => $this->urls->generate(CoreRoutes::GALLERY),
            'album' => ['title' => $input('title'), 'description' => $input('description'),
                'visibility' => $input('visibility') ?: 'public'],
            'visibilities' => Gallery::VISIBILITIES,
            'error' => $error,
        ] + $this->forms($viewer), $error === null ? 200 : 422);
    }

    private function albumPage(Request $request, GalleryViewer $viewer, GalleryRules $rules, int $albumId): Response
    {
        $album = $this->gallery->album($viewer, $albumId) ?? throw new GalleryRejected('gallery_not_found');
        $url = $this->urls->generate(CoreRoutes::GALLERY_ALBUM, ['album' => (string) $albumId]);
        $input = self::input($request);
        $error = null;

        if ($request->isMethod('POST')) {
            try {
                $notice = match ($input('action')) {
                    'upload' => $this->upload($request, $viewer, $albumId, $rules),
                    'save' => $this->saved($this->gallery->saveAlbum(
                        $viewer,
                        $albumId,
                        $input('title'),
                        $input('description'),
                        $input('visibility'),
                    )),
                    'delete_item' => $this->deleted($this->gallery->deleteItem($viewer, (int) $input('item'))),
                    'delete_album' => $this->albumDeleted($viewer, $albumId),
                    default => throw new GalleryRejected('gallery_denied'),
                };

                if ($notice === 'album_deleted') {
                    return InstallerPage::redirect(
                        $this->urls->generate(CoreRoutes::GALLERY) . '?user=' . $album['user_id'],
                    );
                }

                $this->session->set(self::NOTICE, $this->translator->translate(match ($notice) {
                    'pending' => 'gallery.notice.pending',
                    'saved' => 'gallery.notice.saved',
                    'deleted' => 'gallery.notice.deleted',
                    default => 'gallery.notice.uploaded',
                }));

                return InstallerPage::redirect($url);
            } catch (GalleryRejected $rejected) {
                if ($rejected->diagnostic === 'gallery_denied' && !$album['manageable']) {
                    throw $rejected;
                }

                $error = $this->translator->translate('diagnostics.' . $rejected->diagnostic, $rejected->parameters);
            }
        }

        $items = $this->gallery->items($viewer, $albumId, $request->query->getInt('before'));
        $notice = $this->session->get(self::NOTICE);
        $this->session->remove(self::NOTICE);

        return $this->render('gallery/album.twig', [
            'title' => (string) $album['title'],
            'album' => $album,
            'action' => $url,
            'back_url' => $this->urls->generate(CoreRoutes::GALLERY) . '?user=' . $album['user_id'],
            'images' => array_slice($items, 0, Gallery::PAGE),
            'more' => count($items) > Gallery::PAGE ? $url . '?before=' . $items[Gallery::PAGE - 1]['id'] : null,
            'visibilities' => Gallery::VISIBILITIES,
            'rules' => ['max_mb' => intdiv($rules->maxBytes, 1048576), 'types' => implode(', ', $rules->extensions),
                'accept' => implode(',', array_map(static fn(string $one): string => '.' . $one, $rules->extensions)),
                'quota_mb' => intdiv($rules->quotaBytes, 1048576), 'approval' => $rules->approval,
                'used_mb' => $album['manageable']
                    ? round($this->gallery->usage((int) $album['user_id']) / 1048576, 1) : 0],
            'error' => $error,
            'notice' => is_string($notice) ? $notice : null,
        ] + $this->forms($viewer), $error === null ? 200 : 422);
    }

    private function itemPage(Request $request, GalleryViewer $viewer, int $itemId): Response
    {
        $item = $this->gallery->item($viewer, $itemId) ?? throw new GalleryRejected('gallery_not_found');

        return $this->render('gallery/item.twig', [
            'title' => (string) $item['title'],
            'item' => $item,
            'album_url' => $this->urls->generate(CoreRoutes::GALLERY_ALBUM, ['album' => (string) $item['album_id']]),
            'delete_action' => $item['manageable']
                ? $this->urls->generate(CoreRoutes::GALLERY_ALBUM, ['album' => (string) $item['album_id']]) : null,
        ] + $this->forms($viewer));
    }

    private function pending(Request $request, GalleryViewer $viewer): Response
    {
        if (!$viewer->admin && !$viewer->moderator) {
            throw new GalleryRejected('gallery_not_found');
        }

        $url = $this->urls->generate(CoreRoutes::GALLERY_PENDING);

        if ($request->isMethod('POST')) {
            $input = self::input($request);
            $input('action') === 'approve'
                ? $this->gallery->approve($viewer, (int) $input('item'))
                : $this->gallery->deleteItem($viewer, (int) $input('item'));

            return InstallerPage::redirect($url);
        }

        return $this->render('gallery/pending.twig', [
            'title' => $this->translator->translate('gallery.pending'),
            'action' => $url,
            'back_url' => $this->urls->generate(CoreRoutes::GALLERY),
            'images' => array_slice($this->gallery->items($viewer, null, 0, true), 0, Gallery::PAGE),
        ] + $this->forms($viewer));
    }

    /**
     * Composer image (2026-11-09): stores the upload in the member's post-images album and answers with the
     * absolute address for `[img]`, or a translated error. CSRF is checked by the kernel's CsrfGuard.
     */
    private function attach(Request $request, GalleryViewer $viewer, GalleryRules $rules): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store'];

        try {
            if ($viewer->id === null) {
                throw new GalleryRejected('gallery_denied');
            }

            if (!$rules->enabled) {
                throw new GalleryRejected('gallery_post_images_off');
            }

            $file = $request->files->get('file');

            if (!$file instanceof UploadedFile) {
                throw new GalleryRejected('gallery_upload_invalid');
            }

            $album = $this->gallery->attachAlbum($viewer, $this->translator->translate('gallery.attach_album'));
            $id = $this->gallery->upload($viewer, $album, $file, '', $rules);
            $pending = $this->gallery->item($viewer, $id)['status'] === 'pending';

            return new JsonResponse([
                'url' => $request->getSchemeAndHttpHost()
                    . $this->urls->generate(CoreRoutes::GALLERY_FILE, ['item' => (string) $id, 'variant' => 'full']),
                'notice' => $pending ? $this->translator->translate('gallery.attach_pending') : null,
            ], Response::HTTP_OK, $headers);
        } catch (GalleryRejected $rejected) {
            return new JsonResponse([
                'error' => $this->translator->translate('diagnostics.' . $rejected->diagnostic, $rejected->parameters),
            ], Response::HTTP_UNPROCESSABLE_ENTITY, $headers);
        }
    }

    private function upload(Request $request, GalleryViewer $viewer, int $albumId, GalleryRules $rules): string
    {
        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            throw new GalleryRejected('gallery_upload_invalid');
        }

        $title = $request->request->all()['title'] ?? '';
        $id = $this->gallery->upload($viewer, $albumId, $file, is_string($title) ? $title : '', $rules);

        return $this->gallery->item($viewer, $id)['status'] === 'pending' ? 'pending' : 'uploaded';
    }

    private function saved(int $albumId): string
    {
        unset($albumId);

        return 'saved';
    }

    private function deleted(int $albumId): string
    {
        unset($albumId);

        return 'deleted';
    }

    private function albumDeleted(GalleryViewer $viewer, int $albumId): string
    {
        $this->gallery->deleteAlbum($viewer, $albumId);

        return 'album_deleted';
    }

    /** @return callable(string): string */
    private static function input(Request $request): callable
    {
        $input = $request->request->all();

        return static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
    }

    /** @return array<string, string> */
    private function forms(GalleryViewer $viewer): array
    {
        // Guests get no token, so their pages start no session.
        return ['csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $viewer->id === null ? '' : $this->csrf->token()];
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context, int $status = 200): Response
    {
        return new Response($this->views->render($template, $context), $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
