<?php

declare(strict_types=1);

namespace Nolviko\Modules\Moderation\Domain;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Nolviko\Modules\Permissions\Domain\SubjectPermissions;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * Ban, unban and warn users (M04.09). Needs `moderation.user.ban`; nobody sanctions themselves and only an
 * administrator sanctions an administrator. Reasons are 1–500 characters; a ban lasts 1–3650 days or, with 0
 * or empty days, until lifted; a warning carries 1–100 points and counts for 1–3650 days or, with 0 days, forever.
 */
final class ManageSanctions
{
    public const PERMISSION = 'moderation.user.ban';

    public const ADMIN = 'admin.access';

    public const BAN = 'ban';

    public const LIFT = 'lift';

    public const WARN = 'warn';

    public const MAX_DAYS = 3650;

    public const MAX_POINTS = 100;

    private readonly Closure $clock;

    /**
     * @param Closure(int): (array{username: string, admin: bool}|null) $targets The user, or null when unknown.
     * @param (Closure(): DateTimeImmutable)|null $clock Null means the current UTC time.
     */
    public function __construct(
        private readonly UserSanctions $sanctions,
        private readonly Closure $targets,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC'),
        );
    }

    /**
     * What a moderator sees about a user.
     *
     * @return array{username: string, banned: bool, banned_until: ?DateTimeImmutable, points: int}
     *
     * @throws ThreadRuleViolation `moderation_denied` or `user_not_found`.
     */
    public function standing(SubjectPermissions $subject, int $userId): array
    {
        $this->actor($subject);
        $target = ($this->targets)($userId) ?? throw new ThreadRuleViolation('user_not_found');
        $now = ($this->clock)();
        $until = $this->sanctions->activeBanUntil($userId, $now);

        return [
            'username' => $target['username'],
            'banned' => $until !== false,
            'banned_until' => $until === false ? null : $until,
            'points' => $this->sanctions->activePoints($userId, $now),
        ];
    }

    /**
     * @param array<string, mixed> $input `action` (ban, lift or warn), `reason`, `days`, `points`.
     *
     * @throws ThreadRuleViolation `moderation_denied`, `moderation_action_invalid`, `user_not_found`,
     *     `sanction_self`, `sanction_admin_denied`, `sanction_reason_invalid` (`max`), `sanction_days_invalid`
     *     (`max`), `warning_points_invalid` (`max`) or `user_not_banned`.
     */
    public function apply(SubjectPermissions $subject, int $userId, array $input): void
    {
        $actorId = $this->actor($subject);
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';

        if (!in_array($action, [self::BAN, self::LIFT, self::WARN], true)) {
            throw new ThreadRuleViolation('moderation_action_invalid');
        }

        $target = ($this->targets)($userId) ?? throw new ThreadRuleViolation('user_not_found');

        if ($userId === $actorId) {
            throw new ThreadRuleViolation('sanction_self');
        }

        if ($target['admin'] && !$subject->allows(self::ADMIN)) {
            throw new ThreadRuleViolation('sanction_admin_denied');
        }

        $reason = self::reason($input['reason'] ?? null);
        $now = ($this->clock)();

        if ($action === self::LIFT) {
            $this->sanctions->lift($userId, $actorId, $reason, $now);

            return;
        }

        $days = self::days($input['days'] ?? null);
        $expiresAt = $days === 0 ? null : $now->modify('+' . $days . ' days');

        if ($action === self::BAN) {
            $this->sanctions->ban($userId, $actorId, $reason, $expiresAt, $now);

            return;
        }

        $points = self::bounded($input['points'] ?? null, 1, self::MAX_POINTS, 'warning_points_invalid');
        $this->sanctions->warn($userId, $actorId, $points, $reason, $expiresAt, $now);
    }

    private function actor(SubjectPermissions $subject): int
    {
        if ($subject->userId === null || !$subject->allows(self::PERMISSION)) {
            throw new ThreadRuleViolation('moderation_denied');
        }

        return $subject->userId;
    }

    /**
     * A reason of 1–500 characters with whitespace runs collapsed (also used by ManageBans).
     *
     * @throws ThreadRuleViolation `sanction_reason_invalid` (`max`).
     */
    public static function reason(mixed $value): string
    {
        $reason = is_string($value) && mb_check_encoding($value, 'UTF-8') ? $value : '';
        $reason = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $reason));

        if ($reason === '' || mb_strlen($reason) > ReportPost::MAX_REASON_LENGTH || preg_match('/\p{Cc}/u', $reason)) {
            throw new ThreadRuleViolation('sanction_reason_invalid', ['max' => ReportPost::MAX_REASON_LENGTH]);
        }

        return $reason;
    }

    /**
     * A duration of 0–3650 days; empty means 0, which is no end (also used by ManageBans).
     *
     * @throws ThreadRuleViolation `sanction_days_invalid` (`max`).
     */
    public static function days(mixed $value): int
    {
        return self::bounded($value === null || $value === '' ? 0 : $value, 0, self::MAX_DAYS, 'sanction_days_invalid');
    }

    private static function bounded(mixed $value, int $min, int $max, string $diagnostic): int
    {
        $text = is_string($value) || is_int($value) ? trim((string) $value) : '';

        if (preg_match('/^[0-9]{1,5}$/', $text) !== 1 || (int) $text < $min || (int) $text > $max) {
            throw new ThreadRuleViolation($diagnostic, ['max' => $max]);
        }

        return (int) $text;
    }
}
