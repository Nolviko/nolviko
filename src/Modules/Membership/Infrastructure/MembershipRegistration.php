<?php

declare(strict_types=1);

namespace Nolviko\Modules\Membership\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Session\Session;
use Nolviko\Core\Settings\SiteSettings;
use Nolviko\Modules\Community\Infrastructure\MemberStanding;
use Nolviko\Modules\Users\Domain\IdentityNormalizer;
use Nolviko\Modules\Users\Domain\RegistrationExtras;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Throwable;

/**
 * Sign-up additions (roadmap 07, 10, 21, 30, 31) read from Admin > Membership:
 * - reserved usernames (`membership.reserved_names`, comma separated, `name*` reserves a prefix);
 * - membership terms (`membership.terms`): accepted on the normal form and on the first outside-account sign-in;
 *   the acceptance stores the SHA-256 of the text it was given. Imported members get no acceptance row: nothing
 *   is invented for them;
 * - required profile fields; the optional date of birth (`membership.birth_date`);
 * - one question from the administrator's pool for the page language (`membership.questions`, normal form only:
 *   an outside provider already verified the address).
 */
final class MembershipRegistration implements RegistrationExtras
{
    public const TERMS_FIELD = 'accept_terms';

    public const ANSWER_FIELD = 'pool_answer';

    private const QUESTION_KEY = 'register.pool_question';

    /**
     * @param Closure(): string $locale The page language.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly SiteSettings $settings,
        private readonly Session $session,
        private readonly Closure $locale,
    ) {
    }

    public static function termsHash(string $terms): string
    {
        return hash('sha256', str_replace("\r\n", "\n", trim($terms)));
    }

    public function reserved(string $username): bool
    {
        return self::matches((string) $this->settings->get('membership.reserved_names'), $username);
    }

    /** True when the username is one of the comma-separated names, or starts with a `prefix*` entry. */
    public static function matches(string $list, string $username): bool
    {
        $name = IdentityNormalizer::username(trim($username));

        foreach (explode(',', $list) as $entry) {
            $entry = trim($entry);
            $prefix = str_ends_with($entry, '*');
            $entry = IdentityNormalizer::username(rtrim($entry, '*'));

            if ($entry !== '' && ($prefix ? str_starts_with($name, $entry) : $name === $entry)) {
                return true;
            }
        }

        return false;
    }

    public function form(bool $external): array
    {
        $question = null;

        if (!$external && $this->settings->get('membership.questions') !== 'off') {
            $picked = (new RegistrationQuestions($this->db))->pick(($this->locale)());
            $this->session->set(self::QUESTION_KEY, $picked['id'] ?? 0);
            $question = $picked['question'] ?? null;
        }

        $terms = trim((string) $this->settings->get('membership.terms'));

        return ['terms' => $terms === '' ? null : $terms, 'fields' => $this->requiredFields(),
            'birth_date' => $this->settings->get('membership.birth_date') === 'on', 'question' => $question];
    }

    public function check(array $input, bool $external): array
    {
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $diagnostics = [];

        if (trim((string) $this->settings->get('membership.terms')) !== '' && $text(self::TERMS_FIELD) !== '1') {
            $diagnostics[] = 'terms_required';
        }

        try {
            MemberStanding::check($this->requiredFields(), $input);
        } catch (RegistrationRejected $rejected) {
            $diagnostics = [...$diagnostics, ...$rejected->diagnostics];
        }

        if ($this->settings->get('membership.birth_date') === 'on') {
            try {
                MemberOptions::birthDate($text('birth_date'));
            } catch (RegistrationRejected $rejected) {
                $diagnostics = [...$diagnostics, ...$rejected->diagnostics];
            }
        }

        if (!$external) {
            $question = $this->session->get(self::QUESTION_KEY);
            $this->session->remove(self::QUESTION_KEY);

            if (
                is_int($question) && $question > 0
                && !(new RegistrationQuestions($this->db))->answers($question, $text(self::ANSWER_FIELD))
            ) {
                $diagnostics[] = 'register_question_failed';
            }
        }

        return $diagnostics;
    }

    public function record(int $userId, array $input, bool $external): void
    {
        $terms = trim((string) $this->settings->get('membership.terms'));

        try {
            if ($terms !== '') {
                $this->db->insert('membership_terms_acceptances', ['user_id' => $userId,
                    'terms_hash' => self::termsHash($terms), 'source' => $external ? 'external' : 'register',
                    'accepted_at' => gmdate('Y-m-d H:i:s.000000')]);
            }

            $fields = [];

            foreach ($this->requiredFields() as $field) {
                $fields['field_' . $field['id']] = $input['field_' . $field['id']] ?? '';
            }

            if ($fields !== []) {
                (new MemberStanding($this->db))->saveValues($userId, $fields);
            }

            $birth = is_string($input['birth_date'] ?? null) ? trim($input['birth_date']) : '';

            if ($this->settings->get('membership.birth_date') === 'on' && $birth !== '') {
                (new MemberOptions($this->db))->saveBirthDate(
                    $userId,
                    MemberOptions::birthDate($birth),
                    ($input['birth_hide_year'] ?? '') === '1',
                );
            }
        } catch (RegistrationRejected $rejected) {
            throw $rejected;
        } catch (Throwable) {
            // Before migration 0070 the extras have no tables; the account itself is still created.
            return;
        }
    }

    /** @return list<array{id: int, label: string, kind: string, required: bool, options: list<array{id: int, label: string}>}> */
    private function requiredFields(): array
    {
        return array_values(array_filter(
            (new MemberStanding($this->db))->fields(),
            static fn(array $field): bool => $field['required'],
        ));
    }
}
