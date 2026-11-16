<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Domain;

use InvalidArgumentException;
use Nolviko\Modules\Users\Domain\UserStatus;

/** Only filter data is persisted: no cursor, URL, command, executable expression or unknown parameter. */
final class AdminFilterSchema
{
    public const FIELDS = [
        'settings' => ['q', 'group'],
        'members' => ['name', 'status'],
        'audit' => ['family', 'actor', 'target_type', 'target_id'],
    ];

    /** @param array<string, mixed> $input @return array<string, string> */
    public static function clean(string $context, array $input): array
    {
        if (!isset(self::FIELDS[$context]) || count($input) > 12) {
            throw new InvalidArgumentException('admin_filter_invalid');
        }

        $clean = [];

        foreach ($input as $key => $value) {
            if ($key === 'before') {
                continue;
            }

            if (
                !in_array($key, self::FIELDS[$context], true) || !is_string($value)
                || strlen($value) > 512 || !mb_check_encoding($value, 'UTF-8')
                || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            ) {
                throw new InvalidArgumentException('admin_filter_invalid');
            }

            $value = trim($value);

            if ($value === '') {
                continue;
            }

            $valid = match ($key) {
                'q' => grapheme_strlen($value) <= 120,
                'name', 'actor' => grapheme_strlen($value) <= 64,
                'group' => in_array($value, ['locale', 'forum', 'posting', 'registration', 'mail', 'security'], true),
                'status' => UserStatus::tryFrom($value) !== null,
                'family' => in_array($value, ['post', 'thread', 'report', 'user', 'ban', 'admin', 'install'], true),
                'target_type' => preg_match('/^[a-z_]{1,30}$/D', $value) === 1,
                'target_id' => preg_match('/^[1-9][0-9]{0,17}$/D', $value) === 1,
                default => false,
            };

            if (!$valid) {
                throw new InvalidArgumentException('admin_filter_invalid');
            }

            $clean[$key] = $value;
        }

        ksort($clean);

        return $clean;
    }
}
