<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Closure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary closing from the panel (beta.18 roadmap 36): while Admin > Site settings has "site closed" on, every
 * page answers 503 with the administrator's message, except signing in (`auth.*`) and the admin area, so an
 * administrator can always reopen it. Administrators browse the closed site normally. Separate from the update
 * maintenance latch (MaintenanceGuard), which only owner recovery removes.
 */
final readonly class SiteClosedGuard implements RouteGuard
{
    /**
     * @param Closure(): ?string $closedMessage Null while the site is open; otherwise the message ('' for none).
     * @param Closure(Request): bool $administrator Whether the visitor may use the admin area.
     */
    public function __construct(
        private Closure $closedMessage,
        private Closure $administrator,
        private ErrorResponder $errors,
    ) {
    }

    public function guard(array $parameters, Request $request): ?Response
    {
        $route = (string) ($parameters['_route'] ?? '');

        if (str_starts_with($route, 'auth.') || str_starts_with($route, 'admin.')) {
            return null;
        }

        $message = ($this->closedMessage)();

        if ($message === null || ($this->administrator)($request)) {
            return null;
        }

        return $this->errors->closed($message);
    }
}
