<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Until installed, every matched route sends the visitor to the installer; once installed (config plus
 * install lock), installer routes do not exist. Unmatched paths stay 404 in both states.
 */
final class InstallationGuard implements RouteGuard
{
    public function __construct(
        private readonly InstallationState $state,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function guard(array $parameters, Request $request): ?Response
    {
        $installerRoute = in_array($parameters['_route'], CoreRoutes::INSTALLER, true);

        if ($this->state->isInstalled()) {
            if ($installerRoute) {
                throw new ResourceNotFoundException('installer_closed');
            }

            return null;
        }

        if ($installerRoute) {
            return null;
        }

        return new Response('', Response::HTTP_FOUND, [
            'Location' => $this->urls->generate(CoreRoutes::INSTALLER_START),
            'Cache-Control' => 'no-store',
        ]);
    }
}
