<?php

declare(strict_types=1);

namespace Nolviko\Modules\Admin\Infrastructure;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nolviko\Modules\Groups\Domain\BuiltInGroup;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionRuleStore;

/**
 * Forum visibility presets (beta.10): plain choices on the forum screen, stored as ordinary group rules on
 * `forum.view` and `forum.thread.view` so every reader (pages, search, RSS, sitemap, API, attachments) keeps using
 * the one permission resolver. Only the built-in groups' rows for these two keys are touched; any other rule stays.
 * A combination that matches no preset is reported as "custom" and is never overwritten unless the administrator
 * picks a preset. Denials use 0, not `never`, so staff grants on the same forum still work (ADR-011 precedence).
 */
final class ForumGuestVisibility
{
    public const INHERIT = 'inherit';
    public const PUBLIC = 'public';
    public const MEMBERS = 'members';
    public const HIDDEN = 'hidden';
    public const CUSTOM = 'custom';

    /** Choices for a forum; the site default has no "inherit". */
    public const FORUM_CHOICES = [self::INHERIT, self::PUBLIC, self::MEMBERS, self::HIDDEN];
    public const SITE_CHOICES = [self::PUBLIC, self::MEMBERS, self::HIDDEN];

    private const KEYS = [ForumVisibility::VIEW, ForumVisibility::READ];

    public function __construct(
        private readonly Connection $connection,
        private readonly PermissionRuleStore $rules,
    ) {
    }

    /**
     * The preset of each forum, and of the whole site under the key 0.
     *
     * @param list<int> $forumIds
     * @return array<int, string>
     */
    public function presets(array $forumIds): array
    {
        $groups = self::groupIds();
        $rows = [];
        foreach (
            $this->connection->fetchAllAssociative(
                'SELECT usergroup_id, permission_key, value FROM usergroup_permissions '
                . 'WHERE usergroup_id IN (?) AND permission_key IN (?)',
                [$groups, self::KEYS],
                [ArrayParameterType::INTEGER, ArrayParameterType::STRING],
            ) as $row
        ) {
            $rows[0][(int) $row['usergroup_id']][(string) $row['permission_key']] = (string) $row['value'];
        }
        if ($forumIds !== []) {
            foreach (
                $this->connection->fetchAllAssociative(
                    'SELECT forum_id, usergroup_id, permission_key, value FROM forum_permissions '
                    . 'WHERE forum_id IN (?) AND usergroup_id IN (?) AND permission_key IN (?)',
                    [$forumIds, $groups, self::KEYS],
                    [ArrayParameterType::INTEGER, ArrayParameterType::INTEGER, ArrayParameterType::STRING],
                ) as $row
            ) {
                $rows[(int) $row['forum_id']][(int) $row['usergroup_id']][(string) $row['permission_key']]
                    = (string) $row['value'];
            }
        }

        $presets = [0 => self::match($rows[0] ?? [], true)];
        foreach ($forumIds as $id) {
            $presets[$id] = self::match($rows[$id] ?? [], false);
        }

        return $presets;
    }

    /** Runs a forum change and its rules in one transaction. */
    public function transaction(Closure $work): void
    {
        $this->connection->transactional(static function () use ($work): void {
            $work();
        });
    }

    /** Applies a preset to one forum, or to the whole site when the forum is null, in one transaction. */
    public function apply(?int $forumId, string $preset): void
    {
        $choices = $forumId === null ? self::SITE_CHOICES : self::FORUM_CHOICES;
        if (!in_array($preset, $choices, true)) {
            return;
        }
        $target = self::target($preset, $forumId === null);
        $this->connection->transactional(function () use ($target, $forumId): void {
            foreach ($target as $group => $values) {
                foreach ($values as $key => $value) {
                    $this->rules->setGroupRule(
                        $group,
                        $key,
                        $value === null ? null : $value === '1',
                        $forumId,
                    );
                }
            }
        });
    }

    /** @param array<int, array<string, string>> $rows */
    private static function match(array $rows, bool $site): string
    {
        foreach ($site ? self::SITE_CHOICES : self::FORUM_CHOICES as $preset) {
            $target = self::target($preset, $site);
            $same = true;
            foreach ($target as $group => $values) {
                foreach ($values as $key => $value) {
                    $same = $same && ($rows[$group][$key] ?? null) === $value;
                }
            }
            if ($same) {
                return $preset;
            }
        }

        return self::CUSTOM;
    }

    /**
     * Group => key => encoded rule ('1', '0') or null for "no rule here".
     *
     * @return array<int, array<string, ?string>>
     */
    private static function target(string $preset, bool $site): array
    {
        [$view, $read] = self::KEYS;
        $guests = BuiltInGroup::Guests->value;
        $members = BuiltInGroup::Registered->value;
        $staff = [BuiltInGroup::Moderators->value, BuiltInGroup::Administrators->value];
        $none = [$view => null, $read => null];
        $rows = match ($preset) {
            self::PUBLIC => [$guests => [$view => '1', $read => '1'], $members => [$view => '1', $read => '1']],
            self::MEMBERS => [$guests => [$view => '1', $read => '0'], $members => [$view => '1', $read => '1']],
            // Site-wide "hidden" closes the board to guests only; members keep reading.
            self::HIDDEN => $site
                ? [$guests => [$view => '0', $read => '0'], $members => [$view => '1', $read => '1']]
                : [$guests => [$view => '0', $read => '0'], $members => [$view => '0', $read => '0']],
            default => [$guests => $none, $members => $none],
        };
        if (!$site) {
            foreach ($staff as $group) {
                $rows[$group] = $preset === self::HIDDEN ? [$view => '1', $read => '1'] : $none;
            }
        }

        return $rows;
    }

    /** @return list<int> */
    private static function groupIds(): array
    {
        return [
            BuiltInGroup::Guests->value,
            BuiltInGroup::Registered->value,
            BuiltInGroup::Moderators->value,
            BuiltInGroup::Administrators->value,
        ];
    }
}
