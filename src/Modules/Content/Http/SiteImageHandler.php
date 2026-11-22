<?php

declare(strict_types=1);

namespace Nolviko\Modules\Content\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Modules\Content\Infrastructure\SiteImages;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** `/site-images/{key}.{ext}`: smiley and icon files, public and immutable; no database query (ADR-042). */
final readonly class SiteImageHandler implements RouteHandler
{
    public function __construct(
        private SiteImages $images,
        private ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        return $this->images->response((string) ($parameters['key'] ?? ''), (string) ($parameters['ext'] ?? ''))
            ?? $this->errors->notFound();
    }
}
