<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Domain;

use DomainException;

/**
 * A private message the rules refuse with a detail for the sender (beta 17 G1): `conversation_recipient_unknown`
 * and `conversation_box_full` with `name`, `conversation_recipients_limit` with `limit`, `conversation_folder_*`
 * and `conversation_drafts_full`. The diagnostic is a translation key under `diagnostics.`.
 */
final class ConversationRefused extends DomainException
{
    /**
     * @param array<string, int|string> $parameters
     */
    public function __construct(public readonly string $diagnostic, public readonly array $parameters = [])
    {
        parent::__construct($diagnostic);
    }
}
