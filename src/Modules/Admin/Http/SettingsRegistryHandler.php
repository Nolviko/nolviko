<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use InvalidArgumentException;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\View\TemplateRenderer;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Read-only administrator registry; the shared route guard authorizes before this lazy handler is built. */
final readonly class SettingsRegistryHandler implements RouteHandler
{
    public function __construct(
        private SettingsCatalog $catalog,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $input = $request->query->all();
        $query = $input['q'] ?? '';
        $group = $input['group'] ?? '';
        $rows = [];
        $error = null;
        $status = 200;

        try {
            if (!is_string($query) || !is_string($group)) {
                throw new InvalidArgumentException('admin_settings_query_invalid');
            }

            $rows = $this->catalog->search($query, $group);
        } catch (InvalidArgumentException) {
            $status = 422;
            $error = $this->translator->translate('admin.settings.invalid_query');
        } catch (RuntimeException) {
            $status = 503;
            $error = $this->translator->translate('admin.settings.unavailable');
        }

        $query = is_string($query) && mb_check_encoding($query, 'UTF-8') ? mb_substr($query, 0, 120) : '';
        $group = is_string($group) && in_array($group, array_column($this->catalog->groups(), 'id'), true)
            ? $group : '';

        $saveFilterUrl = $this->urls->generate(CoreRoutes::ADMIN_SAVED_FILTERS) . '?'
                . http_build_query(['context' => 'settings', 'f' => ['q' => $query, 'group' => $group]]);

        return new Response($this->views->render('admin/settings.twig', [
            'title' => $this->translator->translate('admin.settings.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_SETTINGS),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_SETTINGS),
            'save_filter_url' => $saveFilterUrl,
            'query' => $query,
            'selected_group' => $group,
            'groups' => $this->catalog->groups(),
            'rows' => $rows,
            'error' => $error,
        ]), $status, ['Cache-Control' => 'no-store, private', 'Content-Type' => 'text/html; charset=UTF-8']);
    }
}
