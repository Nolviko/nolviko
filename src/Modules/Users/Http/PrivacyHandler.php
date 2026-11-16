<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Http\TransportPolicy;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Infrastructure\AccountPrivacy;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Throwable;

/** No target is accepted on self-service routes. Administrative exports/deletions require fresh live permission. */
final class PrivacyHandler implements RouteHandler
{
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly AuthSession $auth,
        private readonly AdminSession $admin,
        private readonly AccountPrivacy $privacy,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ?UserRepository $users = null,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if (!TransportPolicy::allows($request)) {
            return new Response(
                $this->translator->translate('diagnostics.privacy_https_required'),
                403,
                self::headers()
            );
        }

        $subject = $this->subjects->of($request);
        $actor = $subject->userId;

        if ($actor === null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
        }

        $administrative = ($parameters['_route'] ?? '') === CoreRoutes::ADMIN_PRIVACY;
        $fresh = $this->admin->isFresh() && $subject->allows('admin.access');

        if ($administrative && !$fresh) {
            return new Response($this->translator->translate('diagnostics.privacy_denied'), 403, self::headers());
        }

        $input = $request->isMethod('POST') ? $request->request->all() : $request->query->all();
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $target = $administrative ? filter_var(
            $text('target'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        ) : $actor;
        $target = is_int($target) ? $target : null;

        // Administrators pick the account by username (2026-11-07); a member number still works for old links.
        if ($administrative && $target === null && $this->users !== null && trim($text('target_name')) !== '') {
            $target = $this->users->findByUsername(trim($text('target_name')))?->id;
        }

        $targetName = $target === null ? null : $this->users?->findById($target)?->username;
        $error = null;
        $notice = null;
        $status = 200;
        $pending = null;
        $due = [];
        $exportProgress = null;

        try {
            if ($request->isMethod('POST')) {
                if (!TransportPolicy::allows($request) || !$this->csrf->isValid($text(CsrfTokens::FIELD))) {
                    throw new RegistrationRejected(['privacy_denied']);
                }

                $action = $text('action');

                if ($target === null || !in_array($action, ['request', 'cancel', 'finalize', 'export'], true)) {
                    throw new RegistrationRejected(['privacy_action_invalid']);
                }

                if (in_array($action, ['request', 'finalize'], true) && $text('confirm') !== '1') {
                    throw new RegistrationRejected(['privacy_confirmation_required']);
                }

                if ($action === 'export') {
                    $path = $this->privacy->export($subject, $target, $fresh, $text('current_password'));

                    if ($path !== null) {
                        $response = new BinaryFileResponse($path, 200, self::headers(), false);
                        $response->headers->set('Content-Type', 'application/zip');
                        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'nolviko-data.zip');
                        $response->deleteFileAfterSend(true);

                        return $response;
                    }

                    $notice = $this->translator->translate('auth.privacy.export_pending');
                } else {
                    $deleted = $this->privacy->change($subject, $target, $fresh, $text('current_password'), $action);

                    if ($deleted && $target === $actor) {
                        $this->auth->logout($request, true);

                        return InstallerPage::redirect($this->urls->generate(CoreRoutes::LOGIN));
                    }

                    $notice = $this->translator->translate('auth.privacy.updated');
                }
            }

            $pending = $target === null ? null : $this->privacy->pending($subject, $target, $fresh);
            $exportProgress = $target === null ? null : $this->privacy->exportProgress($subject, $target, $fresh);
            $due = $administrative ? $this->privacy->due($subject, $fresh) : [];
        } catch (RegistrationRejected $failure) {
            $error = $this->translator->translate('diagnostics.' . $failure->diagnostics[0]);
            $status = 422;
        } catch (RegistrationThrottled $failure) {
            $error = $this->translator->translate('diagnostics.account_wait', ['seconds' => $failure->seconds]);
            $status = 429;
        } catch (Throwable) {
            $error = $this->translator->translate('diagnostics.privacy_unavailable');
            $status = 503;
        }

        $route = $administrative ? CoreRoutes::ADMIN_PRIVACY : CoreRoutes::ACCOUNT_PRIVACY;
        $response = new Response($this->views->render('auth/privacy.twig', [
            'title' => $this->translator->translate('auth.privacy.title'),
            'action' => $this->urls->generate($route),
            'admin' => $administrative,
            'self' => $target === $actor,
            'target' => $target,
            'target_name' => $targetName,
            'target_unknown' => $administrative && $target === null && trim($text('target_name')) !== '',
            'pending' => $pending,
            'due' => $due,
            'export_progress' => $exportProgress,
            'grace_days' => AccountPrivacy::GRACE_DAYS,
            'error' => $error,
            'notice' => $notice,
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
        ]), $status, self::headers());

        if ($status === 429) {
            $response->headers->set('Retry-After', '5');
        }

        return $response;
    }

    /** @return array<string, string> */
    private static function headers(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'Pragma' => 'no-cache'];
    }
}
