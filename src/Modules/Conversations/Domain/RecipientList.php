<?php

declare(strict_types=1);

namespace Nolviko\Modules\Conversations\Domain;

use Nolviko\Modules\Users\Domain\IdentityNormalizer;

/**
 * Parses the recipient fields of a private message: user names separated by commas, semicolons or line breaks.
 * Duplicates (after username normalization) collapse; a name in both the visible and the hidden list is refused,
 * so the sender always decides explicitly who is hidden.
 */
final class RecipientList
{
    public const MAX_BYTES = 1000;

    /**
     * @return array<string, string> Normalized user name => the name as typed.
     *
     * @throws ConversationRefused `conversation_recipient_unknown` for a field that cannot hold user names.
     */
    public static function parse(string $field): array
    {
        if (strlen($field) > self::MAX_BYTES || !mb_check_encoding($field, 'UTF-8')) {
            throw new ConversationRefused('conversation_recipient_unknown', ['name' => '']);
        }

        $names = [];

        foreach (preg_split('/[,;\r\n]+/', $field) ?: [] as $name) {
            $name = trim($name);

            if ($name === '') {
                continue;
            }

            if (preg_match('/[\x00-\x1f\x7f]/', $name) === 1 || mb_strlen($name, 'UTF-8') > 64) {
                throw new ConversationRefused('conversation_recipient_unknown', ['name' => mb_substr($name, 0, 64)]);
            }

            $names[IdentityNormalizer::username($name)] ??= $name;
        }

        return $names;
    }

    /**
     * @return array{visible: array<string, string>, hidden: array<string, string>}
     *
     * @throws ConversationRefused `conversation_recipient_required`, `conversation_recipients_limit` (`limit`) or
     *     `conversation_recipient_twice` (`name`).
     */
    public static function both(string $visible, string $hidden, int $limit): array
    {
        $shown = self::parse($visible);
        $secret = self::parse($hidden);

        foreach ($secret as $key => $name) {
            if (isset($shown[$key])) {
                throw new ConversationRefused('conversation_recipient_twice', ['name' => $name]);
            }
        }

        if ($shown === [] && $secret === []) {
            throw new ConversationRefused('conversation_recipient_required');
        }

        if (count($shown) + count($secret) > $limit) {
            throw new ConversationRefused('conversation_recipients_limit', ['limit' => $limit]);
        }

        return ['visible' => $shown, 'hidden' => $secret];
    }
}
