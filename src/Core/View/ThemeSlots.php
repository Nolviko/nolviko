<?php

declare(strict_types=1);

namespace Nolviko\Core\View;

use Closure;

/** Versioned additive slots: trusted providers receive only declared DTO fields after capability checks. */
final class ThemeSlots
{
    public const DEFINITIONS = [
        'layout.header.before' => [1, [], null], 'layout.header.after' => [1, [], null],
        'forum.list.before' => [1, ['forums'], null], 'forum.list.after' => [1, ['forums'], null],
        'thread.header.before' => [1, [], null], 'thread.header.after' => [1, [], null],
        'post.header' => [1, ['post'], null], 'post.body.before' => [1, ['post'], null],
        'post.body.after' => [1, ['post'], null], 'post.actions.after' => [1, ['post'], null],
        'user.profile.sidebar' => [1, [], null], 'admin.dashboard.widgets' => [1, [], 'admin.access'],
    ];

    private array $providers = [];

    public function __construct(private readonly ?Closure $authorize = null)
    {
    }

    public static function version(string $id): ?int
    {
        return self::DEFINITIONS[$id][0] ?? null;
    }

    public function add(string $slot, int $version, string $providerId, Closure $render): void
    {
        if (
            self::version($slot) !== $version || preg_match('/^[a-z0-9_.-]{1,80}$/D', $providerId) !== 1
            || isset($this->providers[$slot][$providerId]) || count($this->providers[$slot] ?? []) >= 8
        ) {
            throw new TemplateException('theme_slot_incompatible');
        }
        $this->providers[$slot][$providerId] = $render;
    }

    public function hasProviders(): bool
    {
        return $this->providers !== [];
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
