<?php

declare(strict_types=1);

namespace Nolviko\Modules\Users\Domain;

/**
 * A pluggable anti-bot challenge on the registration form (M02.03a). The built-in one needs no external
 * service; third-party CAPTCHA adapters implement the same contract and are off unless configured.
 */
interface RegistrationChallenge
{
    /**
     * The prompt for the form: a message ID with its parameters, or null when this challenge shows nothing. A
     * proof-of-work challenge (ADR-035) adds `work` (salt, bits, field) for the browser script.
     *
     * @return array{id: string, parameters: array<string, int|string>, work?: array{salt: string, bits: int,
     *     field: string}}|null
     */
    public function present(): ?array;

    /**
     * Whether the submitted form answers the challenge that was presented; each challenge is answered once.
     *
     * @param array<string, mixed> $input The submitted form fields.
     */
    public function passes(array $input): bool;
}
