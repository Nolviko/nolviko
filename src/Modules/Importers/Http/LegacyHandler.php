<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Modules\Importers\Domain\LegacyLink;
use Nolviko\Modules\Importers\Infrastructure\LegacyRedirects;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class LegacyHandler implements RouteHandler
{
    public function __construct(
        private LegacyRedirects $redirects,
        private RequestSubject $subjects,
        private ErrorResponder $errors
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $job = $parameters['job'];
        if (isset($parameters['legacy'])) {
            $engine = $this->redirects->engine($job);
            $link = $engine === null ? null : LegacyLink::parse($engine, $parameters['legacy'], $request->query->all());
        } else {
            $value = $parameters['source'];
            $link = strlen($value) < 19 || strcmp($value, (string) PHP_INT_MAX) <= 0
                ? ['entity' => $parameters['entity'], 'source' => (int) $value] : null;
        }
        $target = $link === null ? null : $this->redirects->target(
            $job,
            $link['entity'],
            $link['source'],
            $this->subjects->of($request)
        );
        return $target === null ? $this->errors->notFound() : new Response(
            '',
            301,
            ['Location' => $target, 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']
        );
    }
}
