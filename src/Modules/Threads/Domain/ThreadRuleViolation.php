<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Domain;

use DomainException;

/**
 * A thread change the rules forbid; the message is a diagnostic ID such as `thread_forum_not_found` or
 * `thread_forum_is_category` (categories group forums and hold no threads). Parameters carry the variable part
 * of the text, for example `seconds` for `post_flood_wait`.
 */
final class ThreadRuleViolation extends DomainException
{
    /**
     * @param array<string, int|string> $parameters
     */
    public function __construct(
        public readonly string $diagnostic,
        public readonly array $parameters = [],
    ) {
        parent::__construct($diagnostic);
    }
}
