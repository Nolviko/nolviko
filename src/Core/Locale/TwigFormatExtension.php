<?php

declare(strict_types=1);

namespace Nolviko\Core\Locale;

use Closure;
use DateTimeInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig filters for the request locale and time zone: `|format_date('medium')`, `|format_time('short')`,
 * `|format_datetime('medium', 'short')` and `|format_number(decimals)`. Results are plain text, so
 * autoescaping still applies. Plural text uses `t('id', {count: n})` with an ICU plural message.
 */
final class TwigFormatExtension extends AbstractExtension
{
    private ?LocaleFormatter $formatter = null;

    /**
     * @param Closure(): LocaleFormatter $create Called on first use, after the Kernel resolved the locale.
     */
    public function __construct(
        private readonly Closure $create,
    ) {
    }

    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter(
                'format_date',
                fn(DateTimeInterface $moment, string $style = 'medium'): string => $this->formatter()->date(
                    $moment,
                    $style,
                ),
            ),
            new TwigFilter(
                'format_time',
                fn(DateTimeInterface $moment, string $style = 'short'): string => $this->formatter()->time(
                    $moment,
                    $style,
                ),
            ),
            new TwigFilter(
                'format_datetime',
                fn(DateTimeInterface $moment, string $date = 'medium', string $time = 'short'): string
                    => $this->formatter()->dateTime($moment, $date, $time),
            ),
            new TwigFilter(
                'format_number',
                fn(int|float $number, ?int $decimals = null): string => $this->formatter()->number($number, $decimals),
            ),
        ];
    }

    private function formatter(): LocaleFormatter
    {
        return $this->formatter ??= ($this->create)();
    }
}
