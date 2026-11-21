<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Groups\Domain\GroupRuleViolation;
use Nolviko\Modules\Membership\Infrastructure\GroupMembership;
use Nolviko\Modules\Membership\Infrastructure\RegistrationQuestions;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/admin/membership` (beta.18, G3): join settings and leaders of custom groups (03), group mail (04), timed
 * memberships (41, ADR-039), promotion rules (42) and the registration question pool (10). Fresh administrator
 * session required; every change is audited.
 */
final readonly class MembershipAdminHandler implements RouteHandler
{
    /** @param list<string> $locales Installed languages for registration questions. */
    public function __construct(
        private GroupMembership $groups,
        private RegistrationQuestions $questions,
        private array $locales,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private Session $session,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
        private DbalAdminAudit $audit,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        $flash = '_membership_notice:' . ($subject->userId ?? 0);
        $notice = $this->session->get($flash);
        $this->session->remove($flash);
        [$status, $error] = [200, null];

        if ($request->isMethod('POST')) {
            $input = $request->request->all();
            $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
            $int = static fn(string $key): int => ctype_digit($text($key)) ? (int) $text($key) : 0;

            if (
                !$this->csrf->isValid($text(CsrfTokens::FIELD)) || !$this->admin->isFresh()
                || $subject->userId === null || !$subject->allows('admin.access')
            ) {
                [$status, $error] = [403, $this->translator->translate('admin.site.denied')];
            } else {
                try {
                    $action = $text('action');
                    match ($action) {
                        'group_settings' => $this->groups->saveSettings(
                            $int('group'),
                            $text('joinable') === '1',
                            $text('description'),
                        ),
                        'add_leader' => $this->groups->addLeader($int('group'), $text('username')),
                        'remove_leader' => $this->groups->removeLeader($int('group'), $int('user')),
                        'grant_timed' => $this->groups->grantTimed(
                            $int('group'),
                            $text('username'),
                            $text('expires'),
                            $subject->userId,
                        ),
                        'end_timed' => $this->groups->endTimed($int('group'), $int('user')),
                        'add_rule' => $this->groups->addRule(
                            $int('group'),
                            $int('min_posts'),
                            $int('min_days'),
                            $int('min_reputation'),
                        ),
                        'delete_rule' => $this->groups->deleteRule($int('id')),
                        'queue_mail' => $this->groups->queueMail(
                            $int('group'),
                            $subject->userId,
                            $text('subject'),
                            $text('body'),
                        ),
                        'add_question' => $this->questions->add(
                            $text('locale'),
                            $text('question'),
                            $text('answers'),
                            $this->locales,
                        ),
                        'delete_question' => $this->questions->delete($int('id')),
                        default => throw new RegistrationRejected(['membership_invalid']),
                    };
                    $this->audit->record($subject->userId, 'membership.' . $action);
                    $this->session->set($flash, $this->translator->translate('admin.taxonomy.saved'));

                    return InstallerPage::redirect($this->urls->generate(CoreRoutes::ADMIN_MEMBERSHIP));
                } catch (RegistrationRejected | GroupRuleViolation $rejected) {
                    $code = $rejected instanceof RegistrationRejected
                        ? ($rejected->diagnostics[0] ?? 'membership_invalid') : 'group_invalid';
                    [$status, $error] = [422, $this->translator->translate('diagnostics.' . explode(':', $code)[0])];
                }
            }
        }

        $groups = $this->groups->groups();

        return new Response($this->views->render('admin/membership.twig', [
            'title' => $this->translator->translate('admin.membership.title'),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_MEMBERSHIP),
            'action' => $this->urls->generate(CoreRoutes::ADMIN_MEMBERSHIP),
            'groups' => $groups,
            'mail_groups' => $this->groups->mailGroups(),
            'timed' => $this->groups->timed(),
            'rules' => $this->groups->rules(),
            'mail_jobs' => $this->groups->mailJobs(),
            'questions' => $this->questions->all(),
            'locales' => $this->locales,
            'error' => $error,
            'notice' => is_string($notice) ? $notice : null,
            'fresh' => $this->admin->isFresh(),
            'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
