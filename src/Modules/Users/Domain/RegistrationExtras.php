<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

/**
 * What a site adds to sign-up (beta.18 roadmap 07, 10, 21, 30, 31): reserved usernames, membership terms, required
 * profile fields, an optional date of birth and the administrator's own verification questions. The normal form
 * and the first sign-in with an outside account use the same object; `$external` tells them apart.
 */
interface RegistrationExtras
{
    /** True when the (already valid) username is on the administrator's reserved list. */
    public function reserved(string $username): bool;

    /**
     * Template context for the form: `terms` (text or null), `fields` (required profile fields), `birth_date`
     * (bool: the date-of-birth field is offered) and `question` (text or null; only the normal form asks one).
     *
     * @return array{terms: ?string, fields: list<array<string, mixed>>, birth_date: bool, question: ?string}
     */
    public function form(bool $external): array;

    /**
     * Form errors as diagnostics (`terms_required`, `profile_field_required:<label>`, `question_failed`, ...).
     * A question is answered once: the expected answer is removed by this call.
     *
     * @param array<string, mixed> $input
     *
     * @return list<string>
     */
    public function check(array $input, bool $external): array;

    /**
     * Stores what the new member gave (terms acceptance, field values, date of birth), inside the transaction
     * that creates the account.
     *
     * @param array<string, mixed> $input
     */
    public function record(int $userId, array $input, bool $external): void;
}
