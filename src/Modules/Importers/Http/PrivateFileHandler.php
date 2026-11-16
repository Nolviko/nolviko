<?php

declare(strict_types=1);

namespace Nolviko\Modules\Importers\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Modules\Importers\Infrastructure\PrivateImportFiles;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/** Native per-owner private archive downloads; global administrators cannot bypass membership. */
final readonly class PrivateFileHandler implements RouteHandler
{
    public function __construct(
        private RequestSubject $subjects,
        private PrivateImportFiles $files,
        private ErrorResponder $errors
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $actor = $this->subjects->of($request)->userId;
        if ($actor === null || !is_string($parameters['file'] ?? null)) {
            return $this->errors->notFound();
        }
        try {
            return $this->files->download($actor, $parameters['file']);
        } catch (RegistrationRejected) {
            return $this->errors->notFound();
        }
    }
}
