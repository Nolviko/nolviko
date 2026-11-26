<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Closure;
use Throwable;

/**
 * Versioned additive slots: trusted providers receive only declared DTO fields after capability checks.
 *
 * Extension API 1.4.0 (2026-11-26): layout slots (`layout.head` inside `<head>`, `layout.content.before/after`
 * around the page content, `layout.body.end`), list slots after each row (`forum.row.after`,
 * `forum.thread.after`, `thread.post.after`; context `position`, 1-based), `thread.posts.after`, cacheable
 * providers (they keep the guest render cache on) and main-menu filters.
 */
final class ThemeSlots
{
    public const DEFINITIONS = [
        'layout.header.before' => [1, [], null], 'layout.header.after' => [1, [], null],
        'forum.list.before' => [1, ['forums'], null], 'forum.list.after' => [1, ['forums'], null],
        'thread.header.before' => [1, [], null], 'thread.header.after' => [1, [], null],
        'post.header' => [1, ['post'], null], 'post.body.before' => [1, ['post'], null],
        'post.body.after' => [1, ['post'], null], 'post.actions.after' => [1, ['post'], null],
        'user.profile.sidebar' => [1, [], null], 'admin.dashboard.widgets' => [1, [], 'admin.access'],
        'layout.head' => [1, [], null], 'layout.content.before' => [1, [], null],
        'layout.content.after' => [1, [], null], 'layout.body.end' => [1, [], null],
        'forum.row.after' => [1, ['position'], null], 'forum.thread.after' => [1, ['position'], null],
        'thread.post.after' => [1, ['position'], null], 'thread.posts.after' => [1, [], null],
    ];

    private const MENU_ITEMS = 40;

    private array $providers = [];

    /** @var array<string, true> Slot => true when one of its providers is not cacheable. */
    private array $uncacheable = [];

    /** @var array<string, Closure> Provider ID => main-menu filter, in run order. */
    private array $menus = [];

    public function __construct(private readonly ?Closure $authorize = null)
    {
    }

    public static function version(string $id): ?int
    {
        return self::DEFINITIONS[$id][0] ?? null;
    }

    public function add(
        string $slot,
        int $version,
        string $providerId,
        Closure $render,
        bool $cacheable = false,
    ): void {
        if (
            self::version($slot) !== $version || preg_match('/^[a-z0-9_.-]{1,80}$/D', $providerId) !== 1
            || isset($this->providers[$slot][$providerId]) || count($this->providers[$slot] ?? []) >= 8
        ) {
            throw new TemplateException('theme_slot_incompatible');
        }
        $this->providers[$slot][$providerId] = $render;
        if (!$cacheable) {
            $this->uncacheable[$slot] = true;
        }
    }

    public function hasProviders(): bool
    {
        return $this->providers !== [];
    }

    /** Whether a provider may give guests different output on the same page (the render cache is then off). */
    public function hasUncacheableProviders(): bool
    {
        return $this->uncacheable !== [];
    }

    public function addMenu(string $providerId, Closure $filter): void
    {
        if (preg_match('/^[a-z0-9_.-]{1,80}$/D', $providerId) !== 1 || isset($this->menus[$providerId])) {
            throw new TemplateException('theme_slot_incompatible');
        }
        $this->menus[$providerId] = $filter;
    }

    /**
     * The main menu after the extension filters. Core's items come from the layout; a filter that throws or
     * returns an invalid list is skipped, so a broken extension never breaks the header.
     *
     * @param list<array{id: string, label: string, url: string, children?: list<array>}> $items
     * @return list<array{id: string, label: string, url: string, children: list<array>}>
     */
    public function menu(array $items): array
    {
        $current = self::menuItems($items, true) ?? [];
        foreach ($this->menus as $filter) {
            try {
                $next = $filter($current);
            } catch (Throwable) {
                continue;
            }
            $current = is_array($next) ? (self::menuItems($next, true) ?? $current) : $current;
        }

        return $current;
    }

    /** @return list<array{id: string, label: string, url: string, children: list<array>}>|null */
    private static function menuItems(array $items, bool $top): ?array
    {
        if (!array_is_list($items) || count($items) > self::MENU_ITEMS) {
            return null;
        }
        $valid = [];
        foreach ($items as $item) {
            if (
                !is_array($item) || !is_string($item['id'] ?? null) || !is_string($item['label'] ?? null)
                || !is_string($item['url'] ?? null) || preg_match('/^[a-z0-9_.:-]{1,80}$/D', $item['id']) !== 1
                || trim($item['label']) === '' || strlen($item['label']) > 120 || strlen($item['url']) > 2000
            ) {
                return null;
            }
            $children = $item['children'] ?? [];
            if (!is_array($children) || (!$top && $children !== [])) {
                return null;
            }
            $children = $top ? self::menuItems($children, false) : [];
            if ($children === null) {
                return null;
            }
            $valid[] = ['id' => $item['id'], 'label' => $item['label'], 'url' => $item['url'],
                'children' => $children];
        }

        return $valid;
    }

    public function render(string $slot, array $context = []): SafeHtml
    {
        $definition = self::DEFINITIONS[$slot] ?? throw new TemplateException('theme_slot_incompatible');
        if (
            array_diff(array_keys($context), $definition[1]) !== []
            || array_diff($definition[1], array_keys($context)) !== []
        ) {
            throw new TemplateException('theme_slot_context_invalid');
        }
        ViewContext::assert($context);
        if (isset($context['position']) && (!is_int($context['position']) || $context['position'] < 1)) {
            throw new TemplateException('theme_slot_context_invalid');
        }
        if (isset($context['forums']) && (!is_array($context['forums']) || !array_is_list($context['forums']))) {
            throw new TemplateException('theme_slot_context_invalid');
        }
        if (isset($context['post']) && !is_array($context['post']) && !$context['post'] instanceof ViewModel) {
            throw new TemplateException('theme_slot_context_invalid');
        }
        if ($definition[2] !== null && ($this->authorize === null || !($this->authorize)($definition[2]))) {
            return new SafeHtml('');
        }
        $html = '';
        foreach ($this->providers[$slot] ?? [] as $provider) {
            $result = $provider($context);
            if (!$result instanceof SafeHtml || strlen($result->html) > 65536) {
                throw new TemplateException('theme_slot_output_invalid');
            }
            $html .= $result->html;
        }
        return new SafeHtml($html);
    }
}
