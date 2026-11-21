<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Http;

use Closure;
use Nolviko\Core\Config\RegistrationRules;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Mail\MailDeliveryFailed;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Users\Domain\NoChallenge;
use Nolviko\Modules\Users\Domain\PasswordPolicy;
use Nolviko\Modules\Users\Domain\RegistrationChallenge;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Nolviko\Modules\Users\Domain\RegistrationThrottled;
use Nolviko\Modules\Users\Infrastructure\AccountRegistration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET `/register` shows the form; POST (CSRF-checked by the kernel guard) registers and redirects to the same
 * "check your inbox" page for every accepted form, so the answer never tells whether the address has an account
 * (M02.03). Form errors re-show the form (422) without the passwords; a mail failure is 503 with the translated
 * "could not send" message.
 *
 * Anti-spam (M02.03a), before any account is looked at: a filled honeypot field is answered like a success and
 * does nothing; a form sent sooner than the minimum fill time after it was shown, or without having been shown,
 * is refused; the configured challenge must be answered; too many accepted forms from one network get 429.
 * In `closed` mode the page says so (403).
 */
final class RegisterHandler implements RouteHandler
{
    public const HONEYPOT = 'website';

    private const SHOWN_AT = 'register.shown_at';

    private readonly Closure $clock;

    /**
     * @param (Closure(): int)|null $clock Unix time; null means time().
     */
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly AuthSession $auth,
        private readonly AccountRegistration $registration,
        private readonly CsrfTokens $csrf,
        private readonly UrlGenerator $urls,
        private readonly ?Session $session = null,
        private readonly RegistrationRules $rules = new RegistrationRules(),
        private readonly RegistrationChallenge $challenge = new NoChallenge(),
        ?Closure $clock = null,
        private readonly ?Closure $external = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function handle(Request $request, array $parameters): Response
    {
        if ($this->auth->userId($request) !== null) {
            return InstallerPage::redirect($this->urls->generate(CoreRoutes::HOME));
        }

        $action = $this->urls->generate(CoreRoutes::REGISTER);

        if ($this->rules->mode === RegistrationRules::CLOSED) {
            return $this->page('auth/register_closed.twig', [
                'title' => $this->translator->translate('auth.register.closed_title'),
            ], Response::HTTP_FORBIDDEN);
        }

        if (!$request->isMethod('POST')) {
            return $request->query->get('sent') === '1'
                ? $this->page('auth/register_sent.twig', [
                    'title' => $this->translator->translate('auth.register.sent_title'),
                    'open' => $this->rules->mode === RegistrationRules::OPEN,
                ])
                : $this->form('', '', [], Response::HTTP_OK);
        }

        $input = $request->request->all();
        $field = static fn(string $name): string => is_string($input[$name] ?? null) ? $input[$name] : '';

        if ($field(self::HONEYPOT) !== '') {
            return InstallerPage::redirect($action . '?sent=1');
        }

        $shownAt = $this->session?->get(self::SHOWN_AT);
        $this->session?->remove(self::SHOWN_AT);

        $tooFast = !is_int($shownAt) || ($this->clock)() - $shownAt < $this->rules->minSeconds;
        $refused = match (true) {
            $this->session !== null && $tooFast => 'register_too_fast',
            !$this->challenge->passes($input) => 'register_challenge_failed',
            default => null,
        };

        // Site additions (beta.18): terms, required fields, date of birth and the administrator's question.
        $extras = $this->registration->extras()?->check($input, false) ?? [];

        if ($refused !== null || $extras !== []) {
            return $this->form(
                $field('username'),
                $field('email'),
                $refused === null ? $extras : [$refused],
                Response::HTTP_UNPROCESSABLE_ENTITY,
                input: $input,
            );
        }

        try {
            $this->registration->register(
                $field('username'),
                $field('email'),
                $field('password'),
                $field('password_confirmation'),
                $request->getClientIp(),
                $input,
            );
        } catch (RegistrationRejected $rejected) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;

            return $this->form($field('username'), $field('email'), $rejected->diagnostics, $status, input: $input);
        } catch (RegistrationThrottled $throttled) {
            $status = Response::HTTP_TOO_MANY_REQUESTS;
            $response = $this->form($field('username'), $field('email'), ['register_wait'], $status, [
                'seconds' => $throttled->seconds,
            ]);
            $response->headers->set('Retry-After', (string) $throttled->seconds);

            return $response;
        } catch (MailDeliveryFailed $failure) {
            $status = Response::HTTP_SERVICE_UNAVAILABLE;

            return $this->form($field('username'), $field('email'), [$failure->diagnostic], $status);
        }

        return InstallerPage::redirect($action . '?sent=1');
    }

    /**
     * @param list<string> $diagnostics
     * @param array<string, int> $parameters
     * @param array<string, mixed> $input The sent form, to fill the site's extra fields in again.
     */
    private function form(
        string $username,
        string $email,
        array $diagnostics,
        int $status,
        array $parameters = [],
        array $input = [],
    ): Response {
        $errors = [];

        foreach ($diagnostics as $diagnostic) {
            [$id, $field] = explode(':', $diagnostic, 2) + [1 => ''];
            $errors[] = $this->translator->translate('diagnostics.' . $id, ['field' => $field] + $parameters);
        }

        $this->session?->set(self::SHOWN_AT, ($this->clock)());
        $challenge = $this->challenge->present();

        return $this->page('auth/register.twig', [
            'title' => $this->translator->translate('auth.register.title'),
            'action' => $this->urls->generate(CoreRoutes::REGISTER),
            'login_url' => $this->urls->generate(CoreRoutes::LOGIN),
            'csrf_field' => CsrfTokens::FIELD,
            'csrf_token' => $this->csrf->token(),
            'username' => $username,
            'email' => $email,
            'errors' => $errors,
            'password_min' => PasswordPolicy::MIN_LENGTH,
            'honeypot' => self::HONEYPOT,
            'challenge' => $challenge === null
                ? null
                : $this->translator->translate($challenge['id'], $challenge['parameters']),
            'challenge_field' => QuestionChallenge::FIELD,
            'challenge_work' => $challenge['work'] ?? null,
            'extras' => self::extrasView($this->registration->extras()?->form(false), $input),
        ], $status);
    }

    /**
     * The site's sign-up additions with the values sent before (no value for the question).
     *
     * @param array<string, mixed>|null $extras
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>|null
     */
    public static function extrasView(?array $extras, array $input): ?array
    {
        if ($extras === null) {
            return null;
        }

        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? $input[$key] : '';
        $extras['fields'] = array_map(
            static fn(array $field): array => $field + ['value' => $text('field_' . $field['id'])],
            $extras['fields'],
        );

        return $extras + ['terms_lines' => preg_split('/\R/u', (string) ($extras['terms'] ?? '')) ?: [],
            'accepted' => $text('accept_terms') === '1', 'birth_value' => $text('birth_date'),
            'hide_year' => $text('birth_hide_year') === '1'];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function page(string $template, array $context, int $status = Response::HTTP_OK): Response
    {
        $context += [
            'resend_url' => $this->urls->generate(CoreRoutes::VERIFY_RESEND),
            'login_url' => $this->urls->generate(CoreRoutes::LOGIN),
            'external' => $this->external === null ? [] : ($this->external)(),
        ];

        return new Response($this->views->render($template, $context), $status, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
