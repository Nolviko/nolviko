<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Typed allowlisted filters; dates use inclusive UTC days and backend queries receive bound parameters only. */
final readonly class SearchFilters
{
    public function __construct(
        public ?int $forum = null,
        public ?int $author = null,
        public ?string $from = null,
        public ?string $until = null,
    ) {
        foreach ([$forum, $author] as $id) {
            if ($id !== null && ($id < 1 || $id > 999999999999999999)) {
                throw new InvalidArgumentException('search_filter_invalid');
            }
        }

        foreach ([$from, $until] as $date) {
            if ($date === null) {
                continue;
            }

            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('search_filter_invalid');
            }
        }

        if ($from !== null && $until !== null && strcmp($from, $until) > 0) {
            throw new InvalidArgumentException('search_filter_invalid');
        }
    }

    public static function parse(array $input): self
    {
        $ids = [];
        $dates = [];

        foreach (['forum', 'author'] as $key) {
            $value = $input[$key] ?? '';

            if (!is_string($value) || ($value !== '' && preg_match('/^[1-9][0-9]{0,17}$/D', $value) !== 1)) {
                throw new InvalidArgumentException('search_filter_invalid');
            }

            $ids[] = $value === '' ? null : (int) $value;
        }

        foreach (['from', 'until'] as $key) {
            $value = $input[$key] ?? '';

            if (!is_string($value)) {
                throw new InvalidArgumentException('search_filter_invalid');
            }

            $dates[] = $value === '' ? null : $value;
        }

        return new self($ids[0], $ids[1], $dates[0], $dates[1]);
    }

    public function query(): array
    {
        return array_filter(['forum' => $this->forum, 'author' => $this->author,
            'from' => $this->from, 'until' => $this->until], static fn(mixed $value): bool => $value !== null);
    }
}
