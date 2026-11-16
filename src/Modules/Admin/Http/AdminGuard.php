<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Closure;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteGuard;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every `admin.*` route (M02.15, M04.01): guests are sent to the login page, signed-in users without
 * `admin.access` get 403. Runs after CsrfGuard in Kernel, so admin POSTs are CSRF-checked first. Other routes
 * pass untouched, and the subject is resolved only for admin routes (a guest costs no database query).
 */
final class AdminGuard implements RouteGuard
{
    public const PREFIX = 'admin.';

    public const PERMISSION = 'admin.access';

    /**
     * @param Closure(): RequestSubject $subjects Lazy, like every handler dependency.
     */
    public function __construct(
        private readonly Closure $subjects,
        private readonly UrlGenerator $urls,
        private readonly ErrorResponder $errors,
    ) {
    }

    public function guard(array $parameters, Request $request): ?Response
    {
        if (!str_starts_with((string) ($parameters['_route'] ?? ''), self::PREFIX)) {
            return null;
        }

        $subjects = ($this->subjects)();

        if ($subjects->userId($request) === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        return $subjects->of($request)->allows(self::PERMISSION) ? null : $this->errors->denied();
    }
}
