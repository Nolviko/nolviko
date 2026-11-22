<?php

declare(strict_types=1);

namespace Nolviko\Modules\Membership\Http;

use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Groups\Domain\GroupRuleViolation;
use Nolviko\Modules\Membership\Infrastructure\GroupMembership;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/groups` (beta.18 roadmap 03): a signed-in member sees the joinable groups and asks to join one; group leaders
 * and administrators decide the pending applications below. Guests get a 404, like other member pages.
 */
final readonly class MemberGroupsHandler implements RouteHandler
{
    public function __construct(
        private GroupMembership $groups,
        private RequestSubject $subjects,
        private CsrfTokens $csrf,
        private Session $session,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
        private ErrorResponder $errors,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $user = $subject->userId;

        if ($user === null) {
            return $this->errors->notFound();
        }

        $administrator = $subject->allows('admin.access');
        $flash = '_groups_notice:' . $user;
        $notice = $this->session->get($flash);
        $this->session->remove($flash);
        [$status, $error] = [200, null];

        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
            $id = ctype_digit($text('id')) ? (int) $text('id') : 0;

            try {
                if (!$this->csrf->isValid($text(CsrfTokens::FIELD))) {
                    throw new RegistrationRejected(['membership_invalid']);
                }

                match ($text('action')) {
                    'apply' => $this->groups->apply($user, $id, $text('message')),
                    'approve', 'reject' => $this->groups->decide($id, $text('action') === 'approve', $user,
                        $administrator),
                    default => throw new RegistrationRejected(['membership_invalid']),
                };
                $this->session->set($flash, $this->translator->translate('community.groups_saved'));

                return new Response('', 303, ['Location' => $this->urls->generate(CoreRoutes::GROUPS),
                    'Cache-Control' => 'private, no-store']);
            } catch (RegistrationRejected | GroupRuleViolation $rejected) {
                $code = $rejected instanceof RegistrationRejected ? ($rejected->diagnostics[0] ?? 'membership_invalid')
                    : 'group_invalid';
                [$status, $error] = [422, $this->translator->translate('diagnostics.' . $code)];
            }
        }

        $html = $this->views->render('community/groups.twig', [
            'title' => $this->translator->translate('community.groups'),
            'action' => $this->urls->generate(CoreRoutes::GROUPS),
            'groups' => $this->groups->joinable($user),
            'queue' => $administrator || $this->groups->leads($user) ? $this->groups->queue($user, $administrator)
                : [],
            'error' => $error,
            'notice' => is_string($notice) ? $notice : null,
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]);

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store']);
    }
}
