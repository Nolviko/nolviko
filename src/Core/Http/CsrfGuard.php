<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Session\CsrfTokens;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every state-changing request (POST, PUT, PATCH, DELETE) needs a valid CSRF token, or it gets 403.
 *
 * The token comes from the `X-CSRF-Token` header or the `_csrf_token` form field. As a second layer, an
 * `Origin` header (or, without one, a `Referer`) must name this site; a request with neither relies on the
 * token alone, since privacy tools strip both. `Origin: null` is refused.
 */
final class CsrfGuard implements RouteGuard
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function __construct(
        private readonly CsrfTokens $tokens,
        private readonly ErrorResponder $errors,
    ) {
    }

    public function guard(array $parameters, Request $request): ?Response
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return null;
        }

        if (!self::sameOrigin($request) || !$this->tokens->isValid(self::token($request))) {
            // An expired logout form (2026-11-14): back to the logout confirmation, which has a fresh form,
            // instead of a dead end. Nothing changes without a valid token.
            if (($parameters['_route'] ?? null) === CoreRoutes::LOGOUT) {
                return new Response('', Response::HTTP_SEE_OTHER, [
                    'Location' => $request->getBaseUrl() . $request->getPathInfo(),
                    'Cache-Control' => 'no-store',
                ]);
            }

            return $this->errors->forbidden();
        }

        return null;
    }

    private static function token(Request $request): ?string
    {
        // all() rather than get(): InputBag::get() throws on an array value, which would become a 500.
        $token = $request->headers->get(CsrfTokens::HEADER) ?? ($request->request->all()[CsrfTokens::FIELD] ?? null);

        return is_string($token) ? $token : null;
    }

    private static function sameOrigin(Request $request): bool
    {
        $site = $request->getSchemeAndHttpHost();
        $origin = $request->headers->get('Origin');

        if ($origin !== null) {
            return $origin === $site;
        }

        $referer = (string) $request->headers->get('Referer');

        return $referer === '' || self::originOf($referer) === $site;
    }

    private static function originOf(string $url): ?string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $suffix = $port === null || $port === (self::DEFAULT_PORTS[$scheme] ?? null) ? '' : ':' . $port;

        return $scheme . '://' . strtolower($parts['host']) . $suffix;
    }
}
