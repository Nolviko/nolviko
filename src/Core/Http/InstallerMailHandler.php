<?php

declare(strict_types=1);

namespace Nolviko\Core\Http;

use Nolviko\Core\Application\InstallationState;
use Nolviko\Core\Config\ConfigException;
use Nolviko\Core\Config\ConfigLoader;
use Nolviko\Core\Installer\MailSetup;
use Nolviko\Core\Installer\SetupCode;
use Nolviko\Core\Mail\MailComposer;
use Nolviko\Core\Mail\MailDeliveryFailed;
use Nolviko\Core\Mail\SymfonyMailer;
use Nolviko\Core\Mail\TransportFactory;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/** Owner-proven optional mail step. Testing never saves credentials; installed sites cannot enter it. */
final class InstallerMailHandler implements RouteHandler
{
    private const LAST_TEST = '_installer_mail_test_at';

    public function __construct(
        private readonly InstallerPage $page,
        private readonly InstallationState $state,
        private readonly SetupCode $setupCode,
        private readonly MailSetup $setup,
        private readonly MailComposer $composer,
        private readonly LoggerInterface $logger,
        private readonly Session $session,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->state->isInstalled()) {
            throw new ResourceNotFoundException('installer_closed');
        }

        if ($this->state === InstallationState::NotConfigured) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::INSTALLER_DATABASE));
        }

        $input = $request->request->all();
        $input['_secure'] = TransportPolicy::allows($request);
        $errors = [];
        $sent = false;
        $status = Response::HTTP_OK;

        if ($request->isMethod('POST')) {
            $code = self::text($input, 'setup_code');

            if (
                !$this->csrf->isValid(self::text($input, CsrfTokens::FIELD))
                || (!$this->setupCode->isProven() && !$this->setupCode->prove($code))
            ) {
                return $this->form($input, ['installer_setup_code_invalid'], false, Response::HTTP_FORBIDDEN);
            }

            try {
                $action = self::text($input, 'action');

                if (
                    $action !== 'skip' && self::text($input, 'transport') === 'smtp'
                    && !TransportPolicy::allows($request)
                ) {
                    return $this->form($input, ['installer_mail_https_required'], false, 403);
                }

                if ($action === 'skip') {
                    $this->setup->save(['transport' => 'mail']);

                    return InstallerPage::redirect($this->urls->generate(CoreRoutes::INSTALLER_ADMIN));
                }

                if ($action === 'save') {
                    $this->setup->save($input);

                    return InstallerPage::redirect($this->urls->generate(CoreRoutes::INSTALLER_ADMIN));
                }

                if ($action !== 'test') {
                    return $this->form($input, ['installer_mail_action_invalid'], false, 422);
                }

                $now = time();
                $last = $this->session->get(self::LAST_TEST, 0);

                if (is_int($last) && $last > $now - 30) {
                    $response = $this->form($input, ['installer_mail_rate_limited'], false, 429);
                    $response->headers->set('Retry-After', '30');

                    return $response;
                }

                $recipient = trim(self::text($input, 'recipient'));

                if (strlen($recipient) > 320 || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                    return $this->form($input, ['installer_mail_recipient_invalid'], false, 422);
                }

                $config = ConfigLoader::fromArray($this->setup->configuration($input));
                $this->session->set(self::LAST_TEST, $now);
                $mailer = new SymfonyMailer(TransportFactory::create($config->mail), $config->mail, $this->logger);
                $mailer->send($this->composer->compose('test', 'mail.test.subject', $recipient, ['name' => '']));
                $sent = true;
            } catch (ConfigException $failure) {
                $errors = $failure->diagnostics();
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } catch (MailDeliveryFailed $failure) {
                $errors = [$failure->diagnostic];
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
            } catch (RuntimeException) {
                $errors = ['config_write_failed'];
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
            }
        }

        return $this->form($input, $errors, $sent, $status);
    }

    /** @param array<string, mixed> $input @param list<string> $errors */
    private function form(array $input, array $errors, bool $sent, int $status): Response
    {
        $values = [];

        foreach ([...MailSetup::FIELDS, 'recipient'] as $field) {
            $values[$field] = substr(self::text($input, $field), 0, 320);
        }

        return $this->page->render('installer/mail.twig', [
            'action' => $this->urls->generate(CoreRoutes::INSTALLER_MAIL),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'needs_code' => !$this->setupCode->isProven(),
            'code_file' => 'storage/' . SetupCode::FILE,
            'values' => $values,
            'errors' => $this->page->messages($errors),
            'sent' => $sent,
            'secure' => ($input['_secure'] ?? false) === true,
        ], $status);
    }

    /** @param array<string, mixed> $input */
    private static function text(array $input, string $field): string
    {
        return is_string($input[$field] ?? null) ? $input[$field] : '';
    }
}
