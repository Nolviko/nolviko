<?php

declare(strict_types=1);

namespace Nolviko\Modules\Membership\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\SqlDialect;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Throwable;

/**
 * A member's own options (roadmap 27, 28, 30, 04): date and time format, a theme among the installed ones (only
 * when the administrator allows it), an optional date of birth with "hide the year", and whether group mail from
 * the administrators may reach them. One row per member, read once per request; a forum without the 0070 table
 * simply has the defaults.
 */
final class MemberOptions
{
    /** Date format => ICU pattern; `auto` keeps the language's own format. */
    public const DATE_FORMATS = ['auto' => null, 'dmy' => 'dd.MM.yyyy', 'mdy' => 'MM/dd/yyyy',
        'ymd' => 'yyyy-MM-dd', 'long' => 'd MMMM yyyy'];

    /** Time format => ICU pattern. */
    public const TIME_FORMATS = ['auto' => null, '24' => 'HH:mm', '12' => 'h:mm a'];

    public const DEFAULTS = ['date_format' => 'auto', 'time_format' => 'auto', 'site_theme' => null,
        'birth_date' => null, 'birth_hide_year' => false, 'group_mail' => true];

    /** @var array<int, array{date_format: string, time_format: string, site_theme: ?string, birth_date: ?string,
     *     birth_hide_year: bool, group_mail: bool}> */
    private array $rows = [];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{date_format: string, time_format: string, site_theme: ?string, birth_date: ?string,
     *     birth_hide_year: bool, group_mail: bool}
     */
    public function of(int $userId): array
    {
        if (isset($this->rows[$userId])) {
            return $this->rows[$userId];
        }

        try {
            $row = $this->db->fetchAssociative('SELECT date_format, time_format, site_theme, birth_date, '
                . 'birth_hide_year, group_mail FROM member_options WHERE user_id = ?', [$userId]);
        } catch (Throwable) {
            $row = false;
        }

        $options = $row === false ? self::DEFAULTS : [
            'date_format' => isset(self::DATE_FORMATS[(string) $row['date_format']])
                ? (string) $row['date_format'] : 'auto',
            'time_format' => isset(self::TIME_FORMATS[(string) $row['time_format']])
                ? (string) $row['time_format'] : 'auto',
            'site_theme' => is_string($row['site_theme']) && $row['site_theme'] !== '' ? $row['site_theme'] : null,
            'birth_date' => is_string($row['birth_date']) ? substr($row['birth_date'], 0, 10) : null,
            'birth_hide_year' => (bool) $row['birth_hide_year'],
            'group_mail' => (bool) $row['group_mail'],
        ];

        return $this->rows[$userId] = $options;
    }

    /**
     * ICU patterns for the member's chosen formats (null = the language's own).
     *
     * @return array{date: ?string, time: ?string}
     */
    public function patterns(int $userId): array
    {
        $options = $this->of($userId);

        return ['date' => self::DATE_FORMATS[$options['date_format']] ?? null,
            'time' => self::TIME_FORMATS[$options['time_format']] ?? null];
    }

    /**
     * Saves the member's options from the form. A theme must be one of `$themes` (the empty value means the
     * site's theme); a birth date is YYYY-MM-DD, not in the future and not before 1900.
     *
     * @param array<string, mixed> $input
     * @param list<string> $themes Themes the member may choose; empty when choosing is off.
     *
     * @throws RegistrationRejected `options_invalid:<field>`.
     */
    public function save(int $userId, array $input, array $themes, bool $birthdays): void
    {
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $current = $this->of($userId);
        $date = $text('date_format') === '' ? 'auto' : $text('date_format');
        $time = $text('time_format') === '' ? 'auto' : $text('time_format');
        $theme = $themes === [] ? $current['site_theme'] : ($text('site_theme') === '' ? null : $text('site_theme'));
        $birth = $birthdays ? self::birthDate($text('birth_date')) : $current['birth_date'];
        $hide = $birthdays ? $text('birth_hide_year') === '1' : $current['birth_hide_year'];

        if (!array_key_exists($date, self::DATE_FORMATS)) {
            throw new RegistrationRejected(['options_invalid:date_format']);
        }

        if (!array_key_exists($time, self::TIME_FORMATS)) {
            throw new RegistrationRejected(['options_invalid:time_format']);
        }

        if ($theme !== null && $themes !== [] && !in_array($theme, $themes, true)) {
            throw new RegistrationRejected(['options_invalid:site_theme']);
        }

        $this->write($userId, ['date_format' => $date, 'time_format' => $time, 'site_theme' => $theme,
            'birth_date' => $birth, 'birth_hide_year' => $hide, 'group_mail' => $text('group_mail') === '1']);
    }

    /** Stores a birth date given at registration (roadmap 30). */
    public function saveBirthDate(int $userId, ?string $date, bool $hideYear): void
    {
        $this->write($userId, ['birth_date' => $date, 'birth_hide_year' => $hideYear] + $this->of($userId));
    }

    /**
     * A valid date of birth, or null for an empty value.
     *
     * @throws RegistrationRejected `options_invalid:birth_date`.
     */
    public static function birthDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));

        if ($date === false || $date->format('Y-m-d') !== $value || $date > $today || (int) $date->format('Y') < 1900) {
            throw new RegistrationRejected(['options_invalid:birth_date']);
        }

        return $value;
    }

    /**
     * Members whose birthday falls on one of the next `$days` days (today first) and whose profile everyone may see,
     * so one cached list serves guests and members alike.
     *
     * @return list<array{id: int, username: string, day: string, age: ?int}>
     */
    public function birthdays(DateTimeImmutable $today, int $days = 7, int $limit = 100): array
    {
        $keys = [];

        for ($offset = 0; $offset < $days; ++$offset) {
            $day = $today->modify('+' . $offset . ' days');
            $keys[(int) $day->format('n') * 100 + (int) $day->format('j')] = $day;
        }

        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT u.id, u.username, o.birth_date, o.birth_hide_year, o.birth_md FROM member_options o '
                    . 'INNER JOIN users u ON u.id = o.user_id LEFT JOIN member_profiles p ON p.user_id = u.id '
                    . "WHERE o.birth_md IN (?) AND u.status = 'active' "
                    . "AND (p.user_id IS NULL OR p.visibility = 'everyone') ORDER BY u.username LIMIT " . $limit,
                [array_keys($keys)],
                [ArrayParameterType::INTEGER],
            );
        } catch (Throwable) {
            return [];
        }

        $order = array_flip(array_keys($keys));
        usort(
            $rows,
            static fn(array $a, array $b): int => $order[(int) $a['birth_md']] <=> $order[(int) $b['birth_md']],
        );

        return array_map(static function (array $row) use ($keys): array {
            $day = $keys[(int) $row['birth_md']];
            $year = (int) substr((string) $row['birth_date'], 0, 4);

            return ['id' => (int) $row['id'], 'username' => (string) $row['username'], 'day' => $day->format('Y-m-d'),
                'age' => (bool) $row['birth_hide_year'] ? null : (int) $day->format('Y') - $year];
        }, $rows);
    }

    /**
     * @param array{date_format: string, time_format: string, site_theme: ?string, birth_date: ?string,
     *     birth_hide_year: bool, group_mail: bool} $options
     */
    private function write(int $userId, array $options): void
    {
        $birth = $options['birth_date'];
        $md = $birth === null ? null : (int) substr($birth, 5, 2) * 100 + (int) substr($birth, 8, 2);
        $this->db->executeStatement(
            SqlDialect::upsert(
                $this->db,
                'INSERT INTO member_options (user_id, date_format, time_format, site_theme, birth_date, '
                    . 'birth_hide_year, birth_md, group_mail, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '
                    . 'utc_timestamp(6))',
                'member_options',
                ['user_id'],
                'date_format = @new.date_format, time_format = @new.time_format, site_theme = @new.site_theme, '
                    . 'birth_date = @new.birth_date, birth_hide_year = @new.birth_hide_year, '
                    . 'birth_md = @new.birth_md, group_mail = @new.group_mail, updated_at = @new.updated_at',
            ),
            [$userId, $options['date_format'], $options['time_format'], $options['site_theme'], $birth,
                $options['birth_hide_year'] ? 1 : 0, $md, $options['group_mail'] ? 1 : 0],
        );
        unset($this->rows[$userId]);
    }
}
