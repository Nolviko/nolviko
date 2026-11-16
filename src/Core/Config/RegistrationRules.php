<?php

declare(strict_types=1);

namespace Nolviko\Core\Config;

/**
 * Registration settings (M02.03a). Modes: `open` (active at once), `verify` (email confirmation, the default),
 * `approval` (email confirmation, then an administrator approves) and `closed`. Every form has a honeypot and a
 * minimum fill time; the challenge is `none` (default) or `question` (built in, no external service); accepted
 * forms are limited per client network.
 */
final readonly class RegistrationRules
{
    public const OPEN = 'open';

    public const VERIFY = 'verify';

    public const APPROVAL = 'approval';

    public const CLOSED = 'closed';

    public const MODES = [self::OPEN, self::VERIFY, self::APPROVAL, self::CLOSED];

    public const CHALLENGE_NONE = 'none';

    public const CHALLENGE_QUESTION = 'question';

    /** Proof of work with the sum question as fallback (ADR-035). */
    public const CHALLENGE_WORK = 'work';

    public const CHALLENGES = [self::CHALLENGE_NONE, self::CHALLENGE_QUESTION, self::CHALLENGE_WORK];

    public const DEFAULT_MIN_SECONDS = 3;

    public const DEFAULT_NETWORK_SECONDS = 120;

    /** [minimum, maximum] of the integer settings. */
    public const RANGES = [
        'registration.min_seconds' => [0, 60],
        'registration.ip_interval_seconds' => [0, 86400],
    ];

    public function __construct(
        public string $mode = self::VERIFY,
        public int $minSeconds = self::DEFAULT_MIN_SECONDS,
        public int $networkSeconds = self::DEFAULT_NETWORK_SECONDS,
        public string $challenge = self::CHALLENGE_NONE,
    ) {
    }
}
