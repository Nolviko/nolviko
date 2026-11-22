<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Core\Cache\Cache;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Throwable;

/**
 * Member standing (2026-11-08): administrator-defined profile fields and post-count ranks, reputation (reactions
 * other members gave to the member's visible posts) and automatic badges. Lists read a whole page of authors in
 * two grouped queries; a forum without the 0048 tables simply shows none of it.
 */
final readonly class MemberStanding
{
    public const KINDS = ['text', 'url'];

    /** Rank colour => chip class (theme.css), the same set as thread prefixes. */
    public const COLORS = ['neutral' => 'chip', 'accent' => 'chip chip-new', 'blue' => 'chip chip-blue',
        'amber' => 'chip chip-amber', 'danger' => 'chip chip-danger'];

    /** Badge => [measure, threshold]; checked in this order. */
    public const BADGES = [
        'first_post' => ['posts', 1],
        'posts_10' => ['posts', 10],
        'posts_100' => ['posts', 100],
        'posts_1000' => ['posts', 1000],
        'liked_10' => ['reputation', 10],
        'liked_100' => ['reputation', 100],
        'veteran' => ['days', 365],
    ];

    /** Seconds a member's post count and reputation may lag behind on thread pages (2026-11-21 benchmark). */
    public const CACHE_SECONDS = 120;

    public function __construct(private Connection $db, private ?Cache $cache = null)
    {
    }

    /** @return list<array{id: int, label: string, kind: string}> */
    public function fields(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative('SELECT id, label, kind FROM profile_fields ORDER BY id LIMIT 50');
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'label' => (string) $row['label'],
            'kind' => (string) $row['kind']], $rows);
    }

    /** @return array<int, string> Field ID => value. */
    public function values(int $userId): array
    {
        try {
            $rows = $this->db->fetchAllKeyValue(
                'SELECT field_id, value FROM profile_field_values WHERE user_id = ?',
                [$userId],
            );
        } catch (Throwable) {
            return [];
        }

        $values = [];

        foreach ($rows as $field => $value) {
            $values[(int) $field] = (string) $value;
        }

        return $values;
    }

    /**
     * Stores the member's own field values (`field_<id>` inputs); empty removes the value.
     *
     * @param array<string, mixed> $input
     *
     * @throws RegistrationRejected `community_invalid` for an address that is not http(s).
     */
    public function saveValues(int $userId, array $input): void
    {
        $fields = $this->fields();

        if ($fields === []) {
            return;
        }

        $values = [];

        foreach ($fields as $field) {
            $raw = $input['field_' . $field['id']] ?? '';
            $value = CommunityAccess::text(is_string($raw) ? $raw : '', 255);

            if ($value !== '' && $field['kind'] === 'url') {
                $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

                if (!in_array($scheme, ['http', 'https'], true) || filter_var($value, FILTER_VALIDATE_URL) === false) {
                    throw new RegistrationRejected(['community_invalid']);
                }
            }

            $values[$field['id']] = $value;
        }

        $this->db->transactional(static function (Connection $db) use ($userId, $values): void {
            $db->executeStatement('DELETE FROM profile_field_values WHERE user_id = ?', [$userId]);

            foreach ($values as $field => $value) {
                if ($value !== '') {
                    $db->insert('profile_field_values', ['user_id' => $userId, 'field_id' => $field,
                        'value' => $value]);
                }
            }
        });
    }

    /** @return list<array{id: int, title: string, min_posts: int, color: string}> Lowest threshold first. */
    public function ranks(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, title, min_posts, color FROM member_ranks ORDER BY min_posts, id LIMIT 100',
            );
        } catch (Throwable) {
            return [];
        }

        return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title'],
            'min_posts' => (int) $row['min_posts'], 'color' => (string) $row['color']], $rows);
    }

    /**
     * Visible post count, reputation and rank of several members.
     *
     * @param list<int> $userIds
     * @return array<int, array{posts: int, reputation: int, rank: array{title: string, class: string}|null}>
     */
    public function standing(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds, static fn(int $id): bool => $id > 0)));

        if ($userIds === []) {
            return [];
        }

        if ($this->cache !== null) {
            // Counting a member's whole post history on every thread page grows with the member's activity; a
            // short-lived per-member copy keeps the page cost flat.
            $result = [];
            $missing = [];

            foreach ($userIds as $id) {
                $hit = $this->cache->get('standing.' . $id);

                if (is_array($hit)) {
                    $result[$id] = $hit;
                } else {
                    $missing[] = $id;
                }
            }

            if ($missing !== []) {
                foreach ((new self($this->db))->standing($missing) as $id => $standing) {
                    $this->cache->set('standing.' . $id, $standing, self::CACHE_SECONDS);
                    $result[$id] = $standing;
                }
            }

            $ordered = [];

            foreach ($userIds as $id) {
                $ordered[$id] = $result[$id];
            }

            return $ordered;
        }

        $posts = $this->db->fetchAllKeyValue(
            // beta.18 roadmap 32: posts in forums with "counts posts" off do not raise the count or the rank.
            'SELECT p.user_id, COUNT(*) FROM posts p INNER JOIN threads t ON t.id = p.thread_id '
                . "INNER JOIN forums f ON f.id = t.forum_id WHERE p.user_id IN (?) AND p.status = 'visible' "
                . 'AND f.counts_posts = 1 GROUP BY p.user_id',
            [$userIds],
            [ArrayParameterType::INTEGER],
        );
        $reputation = $this->db->fetchAllKeyValue(
            'SELECT p.user_id, COUNT(*) FROM post_reactions r INNER JOIN posts p ON p.id = r.post_id '
                . "WHERE p.user_id IN (?) AND p.status = 'visible' AND r.user_id <> p.user_id GROUP BY p.user_id",
            [$userIds],
            [ArrayParameterType::INTEGER],
        );
        $ranks = $this->ranks();
        $result = [];

        foreach ($userIds as $id) {
            $count = (int) ($posts[$id] ?? 0);
            $rank = null;

            foreach ($ranks as $candidate) {
                if ($count >= $candidate['min_posts']) {
                    $rank = ['title' => $candidate['title'], 'class' => self::COLORS[$candidate['color']] ?? 'chip'];
                }
            }

            $result[$id] = ['posts' => $count, 'reputation' => (int) ($reputation[$id] ?? 0), 'rank' => $rank];
        }

        return $result;
    }

    /**
     * Badges earned from the standing and the join date.
     *
     * @param array{posts: int, reputation: int} $standing
     * @return list<string> Badge keys (BADGES).
     */
    public static function badges(array $standing, ?DateTimeImmutable $joined, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $days = $joined === null ? 0 : (int) floor(($now->getTimestamp() - $joined->getTimestamp()) / 86400);
        $measures = ['posts' => $standing['posts'], 'reputation' => $standing['reputation'], 'days' => $days];
        $earned = [];

        foreach (self::BADGES as $badge => [$measure, $threshold]) {
            if ($measures[$measure] >= $threshold) {
                $earned[] = $badge;
            }
        }

        return $earned;
    }

    /** @throws RegistrationRejected `community_invalid`. */
    /**
     * Profile figures (2026-11-09): visible threads the member started in forums the reader may read, and the last
     * visit when the member shows it (`show_online`).
     *
     * @param list<int> $readableForums
     * @return array{threads: int, last_seen: ?string}
     */
    public function profileFacts(int $userId, array $readableForums): array
    {
        $threads = 0;

        if ($readableForums !== []) {
            $threads = (int) $this->db->fetchOne(
                "SELECT COUNT(*) FROM threads WHERE user_id = ? AND status = 'visible' AND forum_id IN (?)",
                [$userId, array_values(array_map('intval', $readableForums))],
                [ParameterType::INTEGER, ArrayParameterType::INTEGER],
            );
        }

        $seen = $this->db->fetchOne(
            'SELECT a.last_seen_at FROM member_activity a LEFT JOIN member_profiles p ON p.user_id = a.user_id '
                . 'WHERE a.user_id = ? AND COALESCE(p.show_online, 1) = 1',
            [$userId],
        );

        return ['threads' => $threads, 'last_seen' => is_string($seen) ? $seen : null];
    }

    public function addField(string $label, string $kind): void
    {
        $label = CommunityAccess::text($label, 60);

        if ($label === '' || !in_array($kind, self::KINDS, true)) {
            throw new RegistrationRejected(['community_invalid']);
        }

        $this->db->insert('profile_fields', ['label' => $label, 'kind' => $kind, 'created_at' => self::now()]);
    }

    /** @throws RegistrationRejected `community_invalid`. */
    public function addRank(string $title, int $minPosts, string $color): void
    {
        $title = CommunityAccess::text($title, 60);

        if ($title === '' || $minPosts < 0 || $minPosts > 10000000 || !isset(self::COLORS[$color])) {
            throw new RegistrationRejected(['community_invalid']);
        }

        $this->db->insert('member_ranks', ['title' => $title, 'min_posts' => $minPosts, 'color' => $color,
            'created_at' => self::now()]);
    }

    public function delete(string $kind, int $id): void
    {
        $this->db->delete($kind === 'rank' ? 'member_ranks' : 'profile_fields', ['id' => $id]);
    }

    private static function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
