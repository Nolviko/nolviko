<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Http;

use InvalidArgumentException;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Infrastructure\GroupAdministration;
use Nolviko\Modules\Admin\Infrastructure\MemberIdentity;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Groups\Domain\GroupRuleViolation;
use Nolviko\Modules\Groups\Domain\UsergroupRepository;
use Nolviko\Modules\Permissions\Domain\PermissionError;
use Nolviko\Modules\Users\Domain\SecondFactors;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Domain\UserStatus;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Modules\Users\Infrastructure\AccountRegistration;
use Nolviko\Modules\Users\Infrastructure\PasswordRecovery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `/admin/members/{user}` (V1 gap list, 2026-11-07): one account with its groups. Administrators add or remove
 * group memberships, choose the primary group, send a password reset email and change the username or email
 * (MemberIdentity: registration rules, audited, pending email-change links voided). Since 2026-11-15 they also
 * turn off a member's second sign-in step.
 */
final readonly class MemberHandler implements RouteHandler
{
    private const ID = '/^[1-9][0-9]{0,17}$/D';

    public function __construct(
        private UserRepository $users,
        private UsergroupRepository $groupRepository,
        private GroupAdministration $groups,
        private MemberIdentity $identity,
        private PasswordRecovery $recovery,
        private RequestSubject $subjects,
        private AdminSession $admin,
        private CsrfTokens $csrf,
        private Session $session,
        private MessageTranslator $translator,
        private TemplateRenderer $views,
        private UrlGenerator $urls,
        private ErrorResponder $errors,
        private ?AccountRegistration $registration = null,
        private ?SecondFactors $factors = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $user = $this->users->findById((int) ($parameters['user'] ?? 0));

        if ($user === null) {
            return $this->errors->notFound();
        }

        $subject = $this->subjects->of($request);
        $input = $request->request->all();
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $group = preg_match(self::ID, $text('group')) === 1 ? (int) $text('group') : 0;
        $self = $this->urls->generate(CoreRoutes::ADMIN_MEMBER, ['user' => (string) $user->id]);
        $flash = '_member_notice:' . ($subject->userId ?? 0);
        $notice = $this->session->get($flash);
        $this->session->remove($flash);
        $error = null;
        $status = 200;

        try {
            if ($request->isMethod('POST')) {
                if (!$this->csrf->isValid($text(CsrfTokens::FIELD))) {
                    throw new PermissionError('group_admin_denied');
                }

                $fresh = $this->admin->isFresh();
                $message = 'admin.groups.saved';

                switch ($text('action')) {
                    case 'add_group':
                        $this->groups->addMember($subject, $fresh, $group, $user->username);
                        break;
                    case 'remove_group':
                        $this->groups->removeMember($subject, $fresh, $group, $user->id);
                        break;
                    case 'primary':
                        $this->groups->setPrimary($subject, $fresh, $group, $user->id);
                        break;
                    case 'rename':
                        $changed = $this->identity->rename($subject, $fresh, $user->id, $text('username'));
                        $message = $changed ? 'admin.member.identity_saved' : 'admin.groups.rules_unchanged';
                        break;
                    case 'change_email':
                        $changed = $this->identity->changeEmail($subject, $fresh, $user->id, $text('email'));
                        $message = $changed ? 'admin.member.identity_saved' : 'admin.groups.rules_unchanged';
                        break;
                    case 'resend_verification':
                        // 2026-11-08: a new confirmation link for an account that never confirmed its address.
                        $this->identity->assertEditable($subject, $fresh, $user->id);

                        if ($this->registration === null || $user->status !== UserStatus::PendingVerification) {
                            throw new InvalidArgumentException('group_action_invalid');
                        }

                        $message = $this->registration->resend($user->email, $request->getClientIp()) > 0
                            ? 'admin.member.reset_wait' : 'admin.member.verify_sent';
                        break;
                    case 'reset_second_factor':
                        // 2026-11-15: a member who lost the device and the recovery codes; the member gets a notice.
                        $this->identity->assertEditable($subject, $fresh, $user->id);
                        if ($this->factors === null) {
                            throw new InvalidArgumentException('group_action_invalid');
                        }
                        $this->factors->reset($user->id);
                        $message = 'admin.member.second_factor_reset_done';
                        break;
                    case 'reset_password':
                        $this->identity->assertEditable($subject, $fresh, $user->id);
                        $message = $this->recovery->request($user->email, $request->getClientIp()) > 0
                            ? 'admin.member.reset_wait' : 'admin.member.reset_sent';
                        break;
                    default:
                        throw new InvalidArgumentException('group_action_invalid');
                }

                $this->session->set($flash, $this->translator->translate($message));

                return InstallerPage::redirect($self);
            }
        } catch (PermissionError) {
            [$status, $error] = [403, $this->translator->translate('admin.groups.denied')];
        } catch (GroupRuleViolation $violation) {
            [$status, $error] = [422, $this->errorText($violation->diagnostic)];
        } catch (InvalidArgumentException $failure) {
            [$status, $error] = [422, $this->errorText($failure->getMessage())];
        } catch (Throwable) {
            [$status, $error] = [503, $this->translator->translate('admin.groups.unavailable')];
        }

        $memberOf = $this->groupRepository->groupIdsOf($user->id);
        $primary = $this->groupRepository->primaryGroupOf($user->id);
        $groups = [];
        $available = [];

        foreach ($this->groupRepository->all() as $item) {
            if (in_array($item->id, $memberOf, true)) {
                $groups[] = ['id' => $item->id, 'name' => $item->name, 'primary' => $item->id === $primary,
                    'url' => $this->urls->generate(CoreRoutes::ADMIN_GROUP, ['group' => (string) $item->id])];
            } elseif ($item->id !== BuiltInGroup::Guests->value) {
                $available[] = ['id' => $item->id, 'name' => $item->name];
            }
        }

        return new Response($this->views->render('admin/member.twig', [
            'title' => $this->translator->translate('admin.member.title', ['name' => $user->username]),
            'nav' => AdminNavigation::items($this->urls, $this->translator, CoreRoutes::ADMIN_MEMBERS),
            'member' => ['id' => $user->id, 'username' => $user->username, 'email' => $user->email,
                'status' => $this->translator->translate('admin.members.status', ['status' => $user->status->value]),
                'joined' => $user->createdAt],
            'groups' => $groups, 'available' => $available,
            'protected' => $user->id !== $subject->userId && $this->identity->isAdministrator($user->id),
            'second_factor' => $this->factors?->hasAny($user->id) === true,
            'pending_verification' => $this->registration !== null && $user->status === UserStatus::PendingVerification,
            'username_value' => $status !== 200 && $text('action') === 'rename' ? $text('username') : $user->username,
            'email_value' => $status !== 200 && $text('action') === 'change_email' ? $text('email') : $user->email,
            'action' => $self, 'list_url' => $this->urls->generate(CoreRoutes::ADMIN_MEMBERS),
            'error' => $error, 'notice' => is_string($notice) ? $notice : null,
            'fresh' => $this->admin->isFresh(), 'reauth_url' => $this->urls->generate(CoreRoutes::ADMIN_REAUTH),
            'csrf_field' => CsrfTokens::FIELD, 'csrf_token' => $this->csrf->token(),
        ]), $status, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    private function errorText(string $diagnostic): string
    {
        $key = 'admin.groups.error.' . $diagnostic;

        return $this->translator->has($key) ? $this->translator->translate($key)
            : $this->translator->translate('admin.groups.unavailable');
    }
}
