<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Modules\Forums\Domain\ForumIndex;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Threads\Infrastructure\ThreadTaxonomy;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/tags/{tag}` (2026-11-08): the discussions list (DiscussionFeed) of one tag, in the reader's readable forums.
 */
final readonly class TagPageHandler implements RouteHandler
{
    public function __construct(
        private RequestSubject $subjects,
        private ForumVisibility $visibility,
        private ForumIndex $index,
        private ThreadTaxonomy $taxonomy,
        private DiscussionFeed $feed,
        private ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $tag = $this->taxonomy->tag((string) ($parameters['tag'] ?? ''));

        if ($tag === null) {
            return $this->errors->notFound();
        }

        $subject = $this->subjects->of($request);
        $readable = $this->visibility->readable($subject);

        return $this->feed->respond(
            $request,
            $readable,
            $this->index->entries($this->visibility->visible($subject), $readable),
            $subject->userId,
            $tag,
        );
    }
}
