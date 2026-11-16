<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use Closure;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\View\ThemeSlots;
use Nolviko\Modules\Permissions\Domain\PermissionDefinition;

/** Per-build registrations, never global state. All registration keys belong to an enabled manifest. */
final class ExtensionRegistry
{
    /** @var array<string, array{definition: RouteDefinition, permission: string}> */
    public array $routes = [];

    /** @var array<string, PermissionDefinition> */
    public array $permissions = [];

    /** @var array<string, array<string, Closure>> */
    public array $subscribers = [];

    /** @var list<array{0: string, 1: string, 2: Closure}> */
    public array $slots = [];

    /** @var array<string, array<string, string>> */
    public array $messages = [];

    /** @var array<string, string> Second-factor provider (service ID) => extension ID (2026-11-15). */
    public array $factors = [];

    /** @var list<Closure(PageMeta): PageMeta> Search-engine metadata filters, in registration order (2026-11-15). */
    public array $pageMeta = [];

    /** @var list<Closure(string): string> robots.txt filters (2026-11-15). */
    public array $robots = [];

    /** @var list<Closure(): ?SitemapRules> (2026-11-15). */
    public array $sitemaps = [];

    /** @var list<Closure(\Symfony\Component\HttpFoundation\Request): ?\Symfony\Component\HttpFoundation\Response> */
    public array $notFound = [];

    /**
     * Address patterns for core routes (2026-11-15): route => ['path' => new path, 'aliases' => older paths].
     *
     * @var array<string, array{path: string, aliases: list<string>}>
     */
    public array $routePatterns = [];

    /** @var array<string, string> Extension ID => route name of its settings page in the admin area. */
    public array $adminPages = [];

    /** @var array<string, string> Identity provider (service ID) => extension ID (2026-11-15). */
    public array $identities = [];

    /** @var (Closure(string): mixed)|null Set once the container is built (ExtensionContext::lazy()). */
    public ?Closure $resolver = null;

    public function __construct(
        public readonly BeforeHooks $before = new BeforeHooks(),
    ) {
    }

    public function applySlots(ThemeSlots $slots): ThemeSlots
    {
        foreach ($this->slots as [$slot, $owner, $render]) {
            $slots->add($slot, 1, str_replace('/', '.', $owner), $render);
        }

        return $slots;
    }

    public function translator(MessageTranslator $core): MessageTranslator
    {
        return new ExtensionTranslator($core, $this->messages);
    }

    /** @return list<RouteDefinition> */
    public function definitions(): array
    {
        return array_map(static fn(array $route): RouteDefinition => $route['definition'], array_values($this->routes));
    }
}
