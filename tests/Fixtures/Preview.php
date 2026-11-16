<?php

declare(strict_types=1);

namespace Nolviko\Tests\Fixtures;

use InvalidArgumentException;

/**
 * Pure planning prototype of fixture contract v1 (docs/FIXTURES.md), not an application schema or a
 * database loader. Output must stay byte-stable: recorded digests depend on it.
 */
final class Preview
{
    private const PROFILE_FIELDS = ['users', 'forums', 'threads', 'posts'];

    private const MAX_COUNT = 10000000;

    /** Fixed UTC anchor 2026-01-01 00:00:00; five posts share each second. */
    private const ANCHOR_TIMESTAMP = 1767225600;

    private const POSTS_PER_SECOND = 5;

    /** Share (in percent) of replies placed in the hot thread #1. */
    private const HOT_THREAD_PERCENT = 20;

    private const BODY_SIZES = [128, 1024, 8192];

    /**
     * @param array<string, int> $profile
     */
    public static function validate(array $profile): void
    {
        if (array_keys($profile) !== self::PROFILE_FIELDS) {
            throw new InvalidArgumentException('Unexpected profile fields/order');
        }

        foreach ($profile as $count) {
            if (!is_int($count) || $count < 1 || $count > self::MAX_COUNT) {
                throw new InvalidArgumentException('Invalid fixture count');
            }
        }

        if ($profile['forums'] > $profile['threads'] || $profile['threads'] > $profile['posts']) {
            throw new InvalidArgumentException('Unreachable forum/thread fixture');
        }
    }

    /**
     * @param array<string, int> $profile
     *
     * @return array<string, int|string>
     */
    public static function post(array $profile, string $seed, int $id): array
    {
        self::validate($profile);

        if ($seed === '' || strlen($seed) > 128 || $id < 1 || $id > $profile['posts']) {
            throw new InvalidArgumentException('Invalid seed or post ID');
        }

        $thread = self::threadFor($profile, $seed, $id);
        $forum = 1 + (($thread - 1) % $profile['forums']);

        return [
            'id' => $id,
            'thread_id' => $thread,
            'forum_id' => $forum,
            'user_id' => 1 + (self::number($seed, $id, 'author') % $profile['users']),
            'visibility_fixture' => self::visibilityFor($forum),
            'status_fixture' => self::statusFor($id),
            'created_at_utc' => self::createdAt($id),
            'body' => self::bodyFor($seed, $id),
        ];
    }

    /**
     * Hash canonical JSON lines in ID order without materializing the dataset.
     *
     * @param array<string, int> $profile
     */
    public static function digest(array $profile, string $seed, int $first, int $last): string
    {
        if ($first < 1 || $last < $first || $last > $profile['posts']) {
            throw new InvalidArgumentException('Invalid digest range');
        }

        $hash = hash_init('sha256');

        for ($id = $first; $id <= $last; ++$id) {
            $line = json_encode(
                self::post($profile, $seed, $id),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            hash_update($hash, $line . "\n");
        }

        return hash_final($hash);
    }

    /**
     * Guard specification only: this class contains no reset or SQL write operation.
     */
    public static function resetAllowed(string $environment, string $database, string $marker): bool
    {
        return $environment === 'test'
            && preg_match('/^nolviko_test_[a-z0-9_]{1,32}$/D', $database) === 1
            && $marker === 'forum-fixture-v1';
    }

    /**
     * Stable pseudo-random number derived from seed, post ID and field name (28 bits).
     */
    private static function number(string $seed, int $id, string $field): int
    {
        return (int) hexdec(substr(hash('sha256', $seed . '/post/' . $id . '/' . $field), 0, 7));
    }

    /**
     * The first post of each thread opens it; later replies go to the hot thread or a stable random thread.
     *
     * @param array<string, int> $profile
     */
    private static function threadFor(array $profile, string $seed, int $id): int
    {
        if ($id <= $profile['threads']) {
            return $id;
        }

        if (self::number($seed, $id, 'hot') % 100 < self::HOT_THREAD_PERCENT) {
            return 1;
        }

        return 1 + (self::number($seed, $id, 'thread') % $profile['threads']);
    }

    private static function visibilityFor(int $forum): string
    {
        return match ($forum % 10) {
            0, 1 => 'private',
            2 => 'staff',
            default => 'public',
        };
    }

    private static function statusFor(int $id): string
    {
        if ($id % 20 === 0) {
            return 'deleted';
        }

        if ($id % 10 === 0) {
            return 'pending';
        }

        return 'published';
    }

    private static function createdAt(int $id): string
    {
        $timestamp = self::ANCHOR_TIMESTAMP + intdiv($id - 1, self::POSTS_PER_SECOND);

        return gmdate('Y-m-d H:i:s', $timestamp) . '.123456';
    }

    private static function bodyFor(string $seed, int $id): string
    {
        $prefix = 'Fixture ' . $id . ' '
            . substr(hash('sha256', $seed . '/body/' . $id), 0, 16)
            . ' English Türkçe العربية 😀 [b]text[/b] ';
        $size = self::BODY_SIZES[self::number($seed, $id, 'size') % count(self::BODY_SIZES)];

        return $prefix . str_repeat('x', $size - strlen($prefix));
    }
}
