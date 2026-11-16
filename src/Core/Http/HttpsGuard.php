<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With `http.require_https`, routes declared `requiresHttps` (login, registration, admin) are moved to HTTPS.
 *
 * GET/HEAD get 301 as specified. Other methods get 308: a 301 lets clients turn a POST into a GET and drop
 * its body, so the form would silently fail. Whether a request is HTTPS honours trusted proxies only.
 */
final class HttpsGuard implements RouteGuard
{
    private const PERMANENT_METHODS = ['GET', 'HEAD'];

    public function __construct(
        private readonly bool $requireHttps,
        private readonly ?SiteUrl $site = null,
    ) {
    }

    public function guard(array $parameters, Request $request): ?Response
    {
        // Read by TransportPolicy: actions that once required HTTPS run over HTTP unless the owner requires it.
        $request->attributes->set(TransportPolicy::ATTRIBUTE, !$this->requireHttps);

        if (!$this->requireHttps || ($parameters[RouteDefinition::HTTPS_ATTRIBUTE] ?? false) !== true) {
            return null;
        }

        if ($request->isSecure()) {
            return null;
        }

        $status = in_array($request->getMethod(), self::PERMANENT_METHODS, true)
            ? Response::HTTP_MOVED_PERMANENTLY
            : Response::HTTP_PERMANENTLY_REDIRECT;

        $configured = $this->site?->absolute($request->getRequestUri());
        $location = $configured === null
            ? 'https://' . $request->getHost() . $request->getRequestUri()
            : preg_replace('~^http://~', 'https://', $configured);

        return new Response('', $status, ['Location' => $location]);
    }
}
