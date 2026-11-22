<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;

/**
 * The admin sidebar (M04.01): plain links, so it works without JavaScript and with the keyboard alone, grouped
 * under section headings. New admin pages are added to the matching group here.
 */
final class AdminNavigation
{
    /**
     * Sidebar sections (2026-11-07): group translation key => [route name => label translation key]. The flat order
     * is kept for the command palette; the layout prints a heading where the group changes.
     */
    private const GROUPS = [
        'admin.nav.group.general' => [
            CoreRoutes::ADMIN_DASHBOARD => 'admin.nav.dashboard',
            CoreRoutes::ADMIN_SITE => 'admin.nav.site',
            CoreRoutes::ADMIN_SETTINGS => 'admin.nav.settings',
            CoreRoutes::ADMIN_REGISTRATION => 'admin.nav.registration',
            CoreRoutes::ADMIN_MAIL => 'admin.nav.mail',
        ],
        'admin.nav.group.forums' => [
            CoreRoutes::ADMIN_FORUMS => 'admin.nav.forums',
            CoreRoutes::ADMIN_FORUM_MODERATORS => 'admin.nav.forum_mod',
            CoreRoutes::ADMIN_TAXONOMY => 'admin.nav.taxonomy',
            CoreRoutes::ADMIN_PERMISSIONS => 'admin.nav.permissions',
        ],
        'admin.nav.group.members' => [
            CoreRoutes::ADMIN_MEMBERS => 'admin.nav.members',
            CoreRoutes::ADMIN_GROUPS => 'admin.nav.groups',
            CoreRoutes::ADMIN_MEMBER_EXTRAS => 'admin.nav.member_extras',
            CoreRoutes::ADMIN_MEMBERSHIP => 'admin.nav.membership',
            CoreRoutes::ADMIN_BANS => 'admin.nav.bans',
            CoreRoutes::ADMIN_PRIVACY => 'admin.nav.privacy',
        ],
        'admin.nav.group.appearance' => [
            CoreRoutes::ADMIN_THEMES => 'admin.nav.themes',
            CoreRoutes::ADMIN_EXTENSIONS => 'admin.nav.extensions',
            CoreRoutes::ADMIN_WEBHOOKS => 'admin.nav.webhooks',
        ],
        'admin.nav.group.records' => [
            CoreRoutes::ADMIN_AUDIT => 'admin.nav.audit',
            CoreRoutes::ADMIN_SAVED_FILTERS => 'admin.nav.saved',
        ],
        'admin.nav.group.system' => [
            CoreRoutes::ADMIN_TASKS => 'tasks.title',
            CoreRoutes::ADMIN_CACHE => 'tasks.cache',
            CoreRoutes::ADMIN_UPDATES => 'admin.nav.updates',
            CoreRoutes::ADMIN_IMPORTS => 'operations.imports',
            CoreRoutes::ADMIN_DIAGNOSTICS => 'operations.diagnostics',
            CoreRoutes::ADMIN_REAUTH => 'admin.nav.reauth',
        ],
        'admin.nav.group.site' => [
            CoreRoutes::HOME => 'admin.nav.board',
        ],
    ];

    /**
     * @return list<NavItemView>
     */
    public static function items(UrlGenerator $urls, MessageTranslator $translator, string $current): array
    {
        $items = [];

        foreach (self::GROUPS as $group => $routes) {
            $heading = $translator->translate($group);

            foreach ($routes as $route => $label) {
                $items[] = new NavItemView(
                    $translator->translate($label),
                    $urls->generate($route),
                    $route === $current,
                    $heading,
                );
            }
        }

        return $items;
    }
}
