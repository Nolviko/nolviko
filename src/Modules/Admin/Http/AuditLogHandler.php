<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Infrastructure\DbalAuditLog;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * GET `/admin/audit` (M04.10, `admin.access` via AdminGuard): who did what to which target, newest first, with
 * filters for the action family, the actor's user name and the target, and an "older" link (keyset, ADR-014).
 * Everything shown is escaped text; details stay the stored JSON.
 */
final class AuditLogHandler implements RouteHandler
{
    /** Action family => translation key of its label; the values of the family filter. */
    public const FAMILIES = [
        'post' => 'admin.audit.family_post',
        'thread' => 'admin.audit.family_thread',
        'report' => 'admin.audit.family_report',
        'user' => 'admin.audit.family_user',
        'ban' => 'admin.audit.family_ban',
        'admin' => 'admin.audit.family_admin',
        'install' => 'admin.audit.family_install',
    ];

    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly DbalAuditLog $log,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $query = $request->query;
        $text = static fn(string $name): string => is_string($query->get($name))
            ? trim((string) $query->get($name))
            : '';
        $family = array_key_exists($text('family'), self::FAMILIES) ? $text('family') : '';
        $before = preg_match('/^[1-9][0-9]{0,17}$/D', $text('before')) === 1 ? (int) $text('before') : null;
        $targetId = preg_match('/^[1-9][0-9]{0,17}$/D', $text('target_id')) === 1 ? (int) $text('target_id') : 0;
        $filters = array_filter([
            'family' => $family,
            'actor' => mb_substr($text('actor'), 0, 64),
            'target_type' => $text('target_type'),
            'target_id' => $targetId,
        ], static fn(string|int $value): bool => $value !== '' && $value !== 0);
        $status = 200;
        $error = null;
        try {
            $rows = $this->log->page($filters, $before);
        } catch (Throwable) {
            $rows = [];
            $status = 503;
            $error = $this->translator->translate('admin.saved.unavailable');
        }
        $older = null;

        if (count($rows) > DbalAuditLog::PAGE_SIZE) {
            array_pop($rows);
            $older = $this->urls->generate(CoreRoutes::ADMIN_AUDIT) . '?' . http_build_query(
                $filters + ['before' => $rows[count($rows) - 1]['id']],
            );
        }

        $families = [[
            'value' => '',
            'label' => $this->translator->translate('admin.audit.family_all'),
            'selected' => $family === '',
        ]];

        foreach (self::FAMILIES as $value => $label) {
            $families[] = [
                'value' => $value,
                'label' => $this->translator->translate($label),
                'selected' => $value === $family,
            ];
        }

        $saveFilterUrl = $this->urls->generate(CoreRoutes::ADMIN_SAVED_FILTERS) . '?'
                . http_build_query(['context' => 'audit', 'f' => $filters]);

        $html = $this->views->render('admin/audit.twig', [
            'title' => $this->translator->translate('admin.audit.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_AUDIT),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_AUDIT),
            'save_filter_url' => $saveFilterUrl,
            'families' => $families,
            'actor' => $filters['actor'] ?? '',
            'target_type' => $filters['target_type'] ?? '',
            'target_id' => $targetId === 0 ? '' : (string) $targetId,
            'rows' => array_map(fn(array $row): array => [
                'at' => $this->translator->translate('admin.audit.time', ['time' => substr($row['created_at'], 0, 19)]),
                'actor' => $row['actor'] ?? $this->translator->translate('admin.audit.system'),
                'action' => $row['action'],
                'target' => $this->translator->translate('admin.audit.target', [
                    'type' => $row['target_type'],
                    'id' => (string) $row['target_id'],
                ]),
                'reason' => $row['reason'] ?? '',
                'details' => $row['details'] ?? '',
            ], $rows),
            'older_url' => $older,
            'error' => $error,
        ]);

        return new Response($html, $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
