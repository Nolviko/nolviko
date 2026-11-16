<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * Add, list and lift IP and email bans (M04.09a); administrators only. Adding takes a type, a value, a reason
 * of 1–500 characters and 0 (until lifted) to 3650 days. An administrator cannot ban the address they are
 * using, so a typo does not lock the site's own administrator out.
 */
final class ManageBans
{
    public const PERMISSION = 'admin.access';

    public const ADD = 'add';

    public const LIFT = 'lift';

    public const LIST_LIMIT = 200;

    private readonly Closure $clock;

    /**
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly BanList $bans,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    /**
     * @return list<array{id: int, entry: BanEntry, reason: string, expires_at: ?DateTimeImmutable}>
     *
     * @throws ThreadRuleViolation `moderation_denied`.
     */
    public function active(SubjectPermissions $subject): array
    {
        $this->actor($subject);

        return $this->bans->active(($this->clock)(), self::LIST_LIMIT);
    }

    /**
     * @param array<string, mixed> $input `action` (add or lift); `type`, `value`, `reason`, `days` or `ban`.
     *
     * @throws ThreadRuleViolation `moderation_denied`, `moderation_action_invalid`, `ban_type_invalid`,
     *     `ban_value_invalid`, `ban_own_address`, `sanction_reason_invalid` (`max`), `sanction_days_invalid`
     *     (`max`) or `ban_not_found`.
     */
    public function apply(SubjectPermissions $subject, array $input, ?string $clientIp): void
    {
        $actorId = $this->actor($subject);
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';
        $now = ($this->clock)();

        if ($action === self::LIFT) {
            $banId = is_string($input['ban'] ?? null) && preg_match('/^[1-9][0-9]{0,17}$/', $input['ban']) === 1
                ? (int) $input['ban']
                : throw new ThreadRuleViolation('ban_not_found');
            $this->bans->lift($banId, $actorId, $now);

            return;
        }

        if ($action !== self::ADD) {
            throw new ThreadRuleViolation('moderation_action_invalid');
        }

        $entry = BanEntry::parse(
            is_string($input['type'] ?? null) ? $input['type'] : '',
            is_string($input['value'] ?? null) ? $input['value'] : '',
        );
        $own = BanEntry::pack($clientIp);

        if (
            $own !== null
            && $entry->ipStart !== null
            && strlen($own) === strlen($entry->ipStart)
            && $entry->ipStart <= $own
            && $own <= (string) $entry->ipEnd
        ) {
            throw new ThreadRuleViolation('ban_own_address');
        }

        $reason = ManageSanctions::reason($input['reason'] ?? null);
        $days = ManageSanctions::days($input['days'] ?? null);
        $this->bans->add($entry, $reason, $days === 0 ? null : $now->modify('+' . $days . ' days'), $actorId, $now);
    }

    private function actor(SubjectPermissions $subject): int
    {
        if ($subject->userId === null || !$subject->allows(self::PERMISSION)) {
            throw new ThreadRuleViolation('moderation_denied');
        }

        return $subject->userId;
    }
}
