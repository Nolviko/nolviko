<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Container\ContainerBuilder;
use Nolviko\Core\Container\Lazy;
use Nolviko\Core\Database\ExtensionSqlGuard;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\View\ThemeSlots;
use Nolviko\Modules\Permissions\Domain\PermissionDefinition;

/** Restricted additive registration surface; declarations must match manifest ownership and core route guards. */
final readonly class ExtensionContext
{
    public function __construct(
        private ExtensionManifest $manifest,
        private ContainerBuilder $services,
        private ExtensionRegistry $registry,
        private ?string $storage = null,
    ) {
    }

    public function table(string $local): string
    {
        return $this->manifest->table($local);
    }

    /** @param list<string> $dependencies */
    public function service(string $id, Closure $factory, array $dependencies = []): void
    {
        $this->owned($id);
        $this->services->factory($id, $factory, $this->guarded($dependencies));
    }

    /**
     * An extension that asks for the database gets its own guarded connection (2026-11-17, ExtensionSqlGuard):
     * free reads, writes only into its own tables (and append-only audit_logs), no schema changes.
     *
     * @param list<mixed> $dependencies
     * @return list<mixed>
     */
    private function guarded(array $dependencies): array
    {
        $id = 'extension.database.' . $this->manifest->id;
        $uses = false;
        foreach ($dependencies as $index => $dependency) {
            if ($dependency === Connection::class) {
                $dependencies[$index] = $id;
                $uses = true;
            } elseif ($dependency instanceof Lazy && $dependency->id === Connection::class) {
                $dependencies[$index] = new Lazy($id);
                $uses = true;
            }
        }
        if ($uses && !$this->services->has($id)) {
            $prefix = $this->manifest->tablePrefix();
            $this->services->factory(
                $id,
                static fn(Connection $base): Connection => ExtensionSqlGuard::connection($base, $prefix),
                [Connection::class],
            );
        }

        return $dependencies;
    }

    /** @param list<string> $dependencies */
    public function route(
        RouteDefinition $route,
        string $permission,
        Closure $factory,
        array $dependencies = [],
    ): void {
        $this->owned($route->name);
        // `/ext/{vendor}/{name}/` (2026-11-17): `/extensions/` collided with the real extensions/ folder when the
        // ZIP root is the document root, so Apache refused those pages. The old prefix stays valid for packages
        // built before the change.
        $owned = str_replace('_', '-', $this->manifest->id) . '/';
        if (
            !(str_starts_with($route->path, '/ext/' . $owned) || str_starts_with($route->path, '/extensions/' . $owned))
            || !in_array($route->name, $this->manifest->data['routes'] ?? [], true)
            || !in_array($permission, $this->manifest->data['permissions'] ?? [], true)
            || isset($this->registry->routes[$route->name])
        ) {
            throw new ExtensionFailure('extension_namespace_invalid');
        }

        $this->services->factory($route->name . '.handler', $factory, $this->guarded($dependencies));
        $this->registry->routes[$route->name] = [
            'definition' => $route, 'permission' => $permission,
        ];
    }

    public function permission(PermissionDefinition $permission): void
    {
        $this->owned($permission->key);
        if (
            !in_array($permission->key, $this->manifest->data['permissions'] ?? [], true)
            || isset($this->registry->permissions[$permission->key])
        ) {
            throw new ExtensionFailure('extension_namespace_invalid');
        }

        $this->registry->permissions[$permission->key] = $permission;
    }

    public function beforePosting(Closure $hook): void
    {
        $this->registry->before->add($this->manifest->id, $hook);
    }

    public function afterCommit(string $event, Closure $handler): void
    {
        if (
            !in_array($event, $this->manifest->data['subscribes'] ?? [], true)
            || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $event) !== 1
            || isset($this->registry->subscribers[$event][$this->manifest->id])
            || count($this->registry->subscribers[$event] ?? []) >= 32
        ) {
            throw new ExtensionFailure('extension_event_invalid');
        }

        $this->registry->subscribers[$event][$this->manifest->id] = $handler;
    }

    /**
     * Offers a service this extension registered (and owns) as a second sign-in step; it must build a
     * SecondFactorProvider (2026-11-15). At most four providers per site.
     */
    public function secondFactor(string $serviceId): void
    {
        $this->owned($serviceId);
        if (isset($this->registry->factors[$serviceId]) || count($this->registry->factors) >= 4) {
            throw new ExtensionFailure('extension_namespace_invalid');
        }

        $this->registry->factors[$serviceId] = $this->manifest->id;
    }

    /**
     * Offers a service this extension owns, building an IdentityProvider, for signing in with an outside account
     * (2026-11-15). At most four per site.
     */
    public function identityProvider(string $serviceId): void
    {
        $this->owned($serviceId);
        if (isset($this->registry->identities[$serviceId]) || count($this->registry->identities) >= 4) {
            throw new ExtensionFailure('extension_namespace_invalid');
        }

        $this->registry->identities[$serviceId] = $this->manifest->id;
    }

    /** Changes the search-engine metadata of rendered pages (title, description, robots, ...; 2026-11-15). */
    public function pageMeta(Closure $filter): void
    {
        $this->registry->pageMeta[] = $filter;
    }

    /** Receives core's robots.txt body and returns the one to serve (2026-11-15). */
    public function robots(Closure $filter): void
    {
        $this->registry->robots[] = $filter;
    }

    /** @param Closure(): ?SitemapRules $rules Read when a sitemap is served (2026-11-15). */
    public function sitemap(Closure $rules): void
    {
        $this->registry->sitemaps[] = $rules;
    }

    /**
     * Asked, in order, before core answers 404 for an address no route matches; may return a response (for
     * example a 301 or 410). Only GET and HEAD reach it (2026-11-15).
     */
    public function notFound(Closure $handler): void
    {
        $this->registry->notFound[] = $handler;
    }

    /**
     * New addresses for public core pages (2026-11-15). Core checks every pattern and ignores the whole set when
     * one is unsafe; the default and the given older addresses keep answering with a 301 to the new one.
     *
     * @param array<string, array{path: string, aliases?: list<string>}> $patterns Route name => pattern.
     */
    public function routePatterns(array $patterns): void
    {
        if ($this->registry->routePatterns !== []) {
            throw new ExtensionFailure('extension_namespace_invalid');
        }

        foreach ($patterns as $route => $pattern) {
            if (!is_string($route) || !is_string($pattern['path'] ?? null)) {
                throw new ExtensionFailure('extension_route_pattern_invalid');
            }
            $aliases = array_values(array_filter($pattern['aliases'] ?? [], is_string(...)));
            $this->registry->routePatterns[$route] = ['path' => $pattern['path'], 'aliases' => $aliases];
        }
    }

    /**
     * A closure that returns one of this extension's own services once the container exists (2026-11-15): hooks
     * are registered before services can be built, and call it when a request needs the service.
     *
     * @return Closure(): mixed
     */
    public function lazy(string $serviceId): Closure
    {
        $this->owned($serviceId);
        $registry = $this->registry;

        return static fn(): mixed => ($registry->resolver ?? throw new ExtensionFailure(
            'extension_service_unavailable',
        ))($serviceId);
    }

    /** Links the extension's own admin route from Admin > Extensions (2026-11-15). */
    public function adminPage(string $route): void
    {
        $this->owned($route);
        $this->registry->adminPages[$this->manifest->id] = $route;
    }

    /**
     * A private directory for the extension's files (2026-11-15), e.g. settings that must be read before the
     * database is connected. Created on first use; never served over the web.
     */
    public function storage(): string
    {
        if ($this->storage === null) {
            throw new ExtensionFailure('extension_storage_unavailable');
        }

        $directory = $this->storage . '/data/' . str_replace('/', '.', $this->manifest->id);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ExtensionFailure('extension_storage_unavailable');
        }

        return $directory;
    }

    /**
     * Adds output to a theme slot. `$cacheable` (API 1.4.0) promises that the output is the same for every guest
     * on the same page (it may differ for members): pages then stay in the 60-second guest render cache, so the
     * output may lag a settings change by up to a minute. Without it, a slot provider turns that cache off.
     */
    public function slot(string $slot, Closure $render, bool $cacheable = false): void
    {
        if (ThemeSlots::version($slot) !== 1 || !in_array($slot, $this->manifest->data['ui_slots'] ?? [], true)) {
            throw new ExtensionFailure('extension_slot_undeclared');
        }

        $count = 0;
        foreach ($this->registry->slots as [$existing, $owner]) {
            if ($existing === $slot) {
                ++$count;
                if ($owner === $this->manifest->id) {
                    throw new ExtensionFailure('extension_hook_duplicate');
                }
            }
        }

        if ($count >= 8) {
            throw new ExtensionFailure('extension_slot_undeclared');
        }

        $this->registry->slots[] = [$slot, $this->manifest->id, $render, $cacheable];
    }

    /**
     * Outside hosts the extension's pages need (API 1.4.0, 2026-11-26), e.g. `['script-src' =>
     * ['https://*.googletagmanager.com']]`. Allowed directives: CspNonce::DIRECTIVES; only https hosts.
     *
     * @param array<string, list<string>> $sources
     */
    public function contentSecurity(array $sources): void
    {
        foreach ($sources as $directive => $list) {
            foreach (is_array($list) ? $list : [] as $source) {
                if (!is_string($directive) || !is_string($source) || !CspNonce::validSource($directive, $source)) {
                    throw new ExtensionFailure('extension_csp_invalid');
                }
                $this->registry->csp[$directive][] = $source;
            }
        }
    }

    /**
     * Changes the main menu (API 1.4.0, 2026-11-26). The filter receives the items so far and returns the new
     * list; each item is `{id, label, url, children}` (children: items without children). Lower `$order` runs
     * first; a menu editor runs last (order 100) to see every other extension's items. An invalid result is
     * ignored and the previous list kept. Core keeps no menu storage.
     *
     * @param Closure(list<array{id: string, label: string, url: string, children: list<array>}>): list<array> $filter
     */
    public function menu(Closure $filter, int $order = 0): void
    {
        foreach ($this->registry->menus as [, $owner]) {
            if ($owner === $this->manifest->id) {
                throw new ExtensionFailure('extension_hook_duplicate');
            }
        }
        $this->registry->menus[] = [max(-100, min(100, $order)), $this->manifest->id, $filter];
    }

    /**
     * Replaces the built-in registration challenge with one of the extension's services (API 1.4.0), e.g. an
     * outside CAPTCHA. The service implements RegistrationChallenge; it should fall back to the built-in one when
     * it is not configured. Only one extension may do this.
     */
    public function registrationChallenge(string $serviceId): void
    {
        $this->owned($serviceId);
        if ($this->registry->challenges !== []) {
            throw new ExtensionFailure('extension_hook_duplicate');
        }
        $this->registry->challenges[$serviceId] = $this->manifest->id;
    }

    private function owned(string $id): void
    {
        if (!str_starts_with($id, $this->manifest->prefix()) || preg_match('/^[a-z0-9_.]+$/D', $id) !== 1) {
            throw new ExtensionFailure('extension_namespace_invalid');
        }
    }
}
