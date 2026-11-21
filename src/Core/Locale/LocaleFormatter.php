<?php

declare(strict_types=1);

namespace Nolviko\Core\Locale;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use InvalidArgumentException;
use NumberFormatter;

/**
 * Locale-aware dates and numbers through ICU (ext-intl, required by ADR-008).
 *
 * Times are stored in UTC and shown in the viewer's time zone, so a moment is always converted, never
 * re-labelled: DST gaps and repeated hours come out as ICU computes them. Styles are ICU's `short`,
 * `medium`, `long` and `full`; their exact text depends on the ICU version and must not be compared literally
 * in tests (use `pattern()`). Plural text is not built here: use an ICU message, `t('id', {count: n})`.
 */
final class LocaleFormatter
{
    public const STYLES = [
        'none' => IntlDateFormatter::NONE,
        'short' => IntlDateFormatter::SHORT,
        'medium' => IntlDateFormatter::MEDIUM,
        'long' => IntlDateFormatter::LONG,
        'full' => IntlDateFormatter::FULL,
    ];

    public readonly DateTimeZone $timeZone;

    /**
     * ICU formatters are costly to build and reusable (2026-11-21 benchmark: building one per date was a fifth of
     * a forum page's CPU), so each style/pattern and fraction setting is built once per formatter instance.
     *
     * @var array<string, IntlDateFormatter>
     */
    private array $dateFormatters = [];

    /** @var array<string, NumberFormatter> */
    private array $numberFormatters = [];

    /**
     * @param string $locale Catalog form (`tr`, `pt_BR`).
     * @param string $timeZone IANA name; an unknown one is refused (`time_zone_invalid`).
     */
    public function __construct(
        public readonly string $locale,
        string $timeZone = 'UTC',
    ) {
        if (!self::isTimeZone($timeZone)) {
            throw new InvalidArgumentException('time_zone_invalid');
        }

        $this->timeZone = new DateTimeZone($timeZone);
    }

    public static function isTimeZone(string $timeZone): bool
    {
        return in_array($timeZone, DateTimeZone::listIdentifiers(), true);
    }

    public function date(DateTimeInterface $moment, string $style = 'medium'): string
    {
        return $this->format($moment, self::style($style), IntlDateFormatter::NONE);
    }

    public function time(DateTimeInterface $moment, string $style = 'short'): string
    {
        return $this->format($moment, IntlDateFormatter::NONE, self::style($style));
    }

    public function dateTime(
        DateTimeInterface $moment,
        string $dateStyle = 'medium',
        string $timeStyle = 'short',
    ): string {
        return $this->format($moment, self::style($dateStyle), self::style($timeStyle));
    }

    /**
     * An explicit ICU pattern (`yyyy-MM-dd HH:mm zzz`) for machine-stable output such as tests and feeds.
     */
    public function pattern(DateTimeInterface $moment, string $pattern): string
    {
        return $this->format($moment, IntlDateFormatter::FULL, IntlDateFormatter::FULL, $pattern);
    }

    /**
     * @param int|null $decimals Fixed fraction digits; null keeps ICU's default (up to 3).
     */
    public function number(int|float $number, ?int $decimals = null): string
    {
        $key = $decimals === null ? 'default' : (string) $decimals;
        $formatter = $this->numberFormatters[$key] ?? null;

        if ($formatter === null) {
            $formatter = new NumberFormatter($this->locale, NumberFormatter::DECIMAL);

            if ($decimals !== null) {
                $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
                $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
            }

            $this->numberFormatters[$key] = $formatter;
        }

        return (string) $formatter->format($number);
    }

    private function format(DateTimeInterface $moment, int $date, int $time, ?string $pattern = null): string
    {
        $local = DateTimeImmutable::createFromInterface($moment)->setTimezone($this->timeZone);
        $key = $date . ':' . $time . ':' . ($pattern ?? '');
        $formatter = $this->dateFormatters[$key] ??= new IntlDateFormatter(
            $this->locale,
            $date,
            $time,
            $this->timeZone,
            IntlDateFormatter::GREGORIAN,
            $pattern,
        );

        return (string) $formatter->format($local);
    }

    private static function style(string $style): int
    {
        return self::STYLES[$style] ?? throw new InvalidArgumentException('date_style_invalid:' . $style);
    }
}
