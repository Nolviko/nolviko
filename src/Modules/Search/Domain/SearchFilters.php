<?php

declare(strict_types=1);

namespace Nolviko\Modules\Search\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Typed allowlisted filters; dates use inclusive UTC days and backend queries receive bound parameters only.
 * beta.18: `thread` keeps the posts of one thread (roadmap 09), `titles` searches thread titles with one result
 * per thread (roadmap 11) and `sort` picks newest (default), oldest or relevance (roadmap 26).
 */
final readonly class SearchFilters
{
    public const NEWEST = 'newest';

    public const OLDEST = 'oldest';

    public const RELEVANCE = 'relevance';

    public const SORTS = [self::NEWEST, self::OLDEST, self::RELEVANCE];

    public function __construct(
        public ?int $forum = null,
        public ?int $author = null,
        public ?string $from = null,
        public ?string $until = null,
        public ?int $thread = null,
        public bool $titles = false,
        public string $sort = self::NEWEST,
    ) {
        foreach ([$forum, $author, $thread] as $id) {
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

        // A thread's posts are not a list of thread titles.
        if (!in_array($sort, self::SORTS, true) || ($titles && $thread !== null)) {
            throw new InvalidArgumentException('search_filter_invalid');
        }
    }

    public static function parse(array $input): self
    {
        $ids = [];
        $dates = [];

        foreach (['forum', 'author', 'thread'] as $key) {
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

        $titles = $input['titles'] ?? '';
        $sort = $input['sort'] ?? '';

        if (!in_array($titles, ['', '0', '1'], true) || !is_string($sort)) {
            throw new InvalidArgumentException('search_filter_invalid');
        }

        return new self($ids[0], $ids[1], $dates[0], $dates[1], $ids[2], $titles === '1', $sort === '' ? self::NEWEST
            : $sort);
    }

    public function query(): array
    {
        return array_filter(['forum' => $this->forum, 'author' => $this->author,
            'from' => $this->from, 'until' => $this->until, 'thread' => $this->thread,
            'titles' => $this->titles ? '1' : null, 'sort' => $this->sort === self::NEWEST ? null : $this->sort],
            static fn(mixed $value): bool => $value !== null);
    }
}
