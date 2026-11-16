<?php

declare(strict_types=1);

namespace Nolviko\Core\Extensions;

/** Explicit account-link boundary requires a fresh core challenge; no automatic email-based linking. */
interface AccountLinkCommand
{
    public function link(AuthChallenge $challenge, ExternalIdentity $identity): void;

    public function unlink(AuthChallenge $challenge, string $provider): void;
}
