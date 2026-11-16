<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Closure;
use LogicException;
use Nolviko\Core\Extensions\ExtensionHandlers;
use Nolviko\Core\Locale\LocaleContext;
use Nolviko\Core\Routing\CanonicalRedirectException;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\MalformedPathException;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\NativeSession;
use Nolviko\Core\Updates\SiteAccessLock;
use Nolviko\Modules\Users\Http\AuthSession;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

/**
 * Error boundary around routing and handlers: every request ends in a Response, never in an uncaught error.
 */
final class Kernel
{
    /**
     * @param array<string, Closure(): RouteHandler> $handlers Route name => lazy handler; only the matched one is
     *     built, so a route never pays for (or connects through) another route's dependencies.
     * @param list<RouteGuard> $guards Checked in order after matching, before the handler is built.
     * @param NativeSession|null $session Bound to each request before routing and committed on every response,
     *     error pages included, so a started session is always written and its lock released.
     * @param ResponseCookies|null $cookies Cookies queued by services, added to every response.
     * @param LocaleContext|null $locale Resolved for each request after the session is bound, before routing.
     * @param list<Closure(Request): ?Response> $notFound Extension answers (2026-11-15) for GET/HEAD addresses no
     *     route matches, asked in order before the 404 page (for example a redirect manager).
     */
    public function __construct(
        private readonly Router $router,
        private readonly array $handlers,
        private readonly ErrorResponder $errors,
        private readonly ErrorReporter $reporter,
        private readonly array $guards = [],
        private readonly ?NativeSession $session = null,
        private readonly ?ResponseCookies $cookies = null,
        private readonly ?LocaleContext $locale = null,
        private readonly ?MaintenanceGuard $maintenance = null,
        private readonly ?ThemeRequest $theme = null,
        private readonly ?ExtensionHandlers $extensions = null,
        private readonly ?Closure $activity = null,
        private readonly ?SiteAccessLock $deployment = null,
        private readonly ?SeoMetadata $seo = null,
        private readonly array $notFound = [],
    ) {
    }

    public function handle(Request $request): Response
    {
        if ($this->deployment === null) {
            return $this->handleUnlocked($request);
        }
        $lease = $this->deployment->acquire(false);
        if ($lease === false) {
            return $this->errors->maintenance();
        }
        try {
            return $this->handleUnlocked($request);
        } finally {
            SiteAccessLock::release($lease);
        }
    }

    private function handleUnlocked(Request $request): Response
    {
        $this->seo?->reset();
        if ($this->theme !== null) {
            $this->theme->request = $request;
        }
        $maintenance = $this->maintenance?->guard([], $request);

        if ($maintenance !== null) {
            return $maintenance;
        }

        if ($this->session === null) {
            $this->locale?->resolve($request);
            $response = $this->respond($request);

            return $this->cookies?->apply($response) ?? $response;
        }

        $this->session->begin($request);
        $this->locale?->resolve($request);
        $response = $this->respond($request);
        $this->cookies?->apply($response);

        if ($response->getStatusCode() < 400 && !str_starts_with($request->getPathInfo(), '/install')) {
            ($this->activity)?->__invoke($request);
        }

        try {
            return $this->session->commit($response);
        } catch (Throwable $error) {
            return $this->failure($error, $request);
        }
    }

    private function respond(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (MalformedPathException) {
            $response = $this->errors->badRequest();
        } catch (CanonicalRedirectException $redirect) {
            // Empty body: protocol redirects have no untranslated HTML or reflected request content.
            $response = new Response('', Response::HTTP_MOVED_PERMANENTLY, ['Location'
                => $redirect->location]);
        } catch (ResourceNotFoundException) {
            $response = $this->extensionAnswer($request) ?? $this->errors->notFound();
        } catch (MethodNotAllowedException $exception) {
            $response = $this->errors->methodNotAllowed($exception->getAllowedMethods());
        } catch (Throwable $error) {
            $response = $this->failure($error, $request);
        }
        return $this->seo?->apply($response) ?? $response;
    }

    /**
     * Report an unexpected failure with a fresh incident ID and return the generic 500 page.
     *
     * The report gets the client address (logged per the IP privacy setting), method and path, never the
     * query string, headers or body, which may carry credentials.
     */
    public function failure(Throwable $error, ?Request $request = null): Response
    {
        $incidentId = IncidentId::generate();
        $context = $request === null ? [] : [
            'client_ip' => $request->getClientIp(),
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
        ];
        $this->reporter->report($incidentId, $error, $context);

        return $this->errors->serverError($incidentId);
    }

    private function extensionAnswer(Request $request): ?Response
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }

        foreach ($this->notFound as $answer) {
            try {
                $response = $answer($request);
            } catch (Throwable) {
                continue;
            }
            if ($response instanceof Response) {
                return $response;
            }
        }

        return null;
    }

    /**
     * An older address of a moved page (RoutePatterns, 2026-11-15): 301 to the current one with the query kept.
     * A thread address without its slug goes to the id-only form, whose handler adds the slug.
     */
    private function aliasRedirect(Request $request, array $parameters): CanonicalRedirectException
    {
        $target = (string) $parameters[RouteDefinition::ALIAS_ATTRIBUTE];
        $values = array_filter(
            $parameters,
            static fn(string $key): bool => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );
        try {
            $location = $this->router->generate($target, $values);
        } catch (Throwable) {
            $fallback = isset($values['page']) ? CoreRoutes::THREAD_ID_PAGE : CoreRoutes::THREAD_ID;
            $location = $this->router->generate($fallback, array_diff_key($values, ['slug' => true]));
        }
        $query = $request->getQueryString();

        return new CanonicalRedirectException($location . ($query === null ? '' : '?' . $query));
    }

    private function dispatch(Request $request): Response
    {
        $parameters = $this->router->match($request);
        if (isset($parameters[RouteDefinition::ALIAS_ATTRIBUTE])) {
            throw $this->aliasRedirect($request, $parameters);
        }
        $this->seo?->bind(
            $parameters,
            $this->router,
            $request->cookies->has(NativeSession::COOKIE)
                || $request->cookies->has(AuthSession::REMEMBER_COOKIE)
        );

        foreach ($this->guards as $guard) {
            $response = $guard->guard($parameters, $request);

            if ($response !== null) {
                return $response;
            }
        }

        $handler = isset($this->handlers[$parameters['_route']]) ? ($this->handlers[$parameters['_route']])() : null;

        $handler ??= $this->extensions?->find($parameters['_route']);

        if (!$handler instanceof RouteHandler) {
            throw new LogicException('route_handler_missing:' . $parameters['_route']);
        }

        return $handler->handle($request, $parameters);
    }
}
