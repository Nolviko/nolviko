<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Users\Domain\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/members?name=` (2026-11-08): where an @mention links to. It leads to the member's profile page, which applies
 * the profile's own visibility; an unknown name is a 404.
 */
final readonly class MemberLinkHandler implements RouteHandler
{
    public function __construct(
        private UserRepository $users,
        private UrlGenerator $urls,
        private ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $name = $request->query->get('name');
        $user = is_string($name) && mb_check_encoding($name, 'UTF-8') && mb_strlen($name) <= 32
            ? $this->users->findByUsername($name) : null;

        if ($user === null) {
            return $this->errors->notFound();
        }

        return InstallerPage::redirect(
            $this->urls->generate(CoreRoutes::COMMUNITY, ['section' => 'profile']) . '?user=' . $user->id,
        );
    }
}
