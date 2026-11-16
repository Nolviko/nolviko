<?php

declare(strict_types=1);

namespace Nolviko\Modules\Seo\Infrastructure;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Core\Events\Outbox;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Core\Extensions\SitemapRules;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Infrastructure\ScopeSql;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use RuntimeException;
use Throwable;

/** Durable derived boundaries, bounded primary-key scans, complete-only publication and current guest ACL on reads. */
final readonly class Sitemaps
{
    public const BATCH = 1000;
    public const MAX_PAGES = 49999;

    public function __construct(
        private Connection $db,
        private ForumVisibility $visibility,
        private Closure $guest,
        private SiteUrl $site,
        private UrlGenerator $urls,
        private ?Closure $clock = null,
        private array $rules = [],
    ) {
    }

    public function active(bool $schedule = true): ?array
    {
        if (!$this->site->configured()) {
            return null;
        }
        $version = $this->version();
        $row = $this->db->fetchAssociative('SELECT id, token, rules_version, origin_hash, state, '
            . 'completed_at '
            . 'FROM seo_sitemap_runs WHERE active_slot = 1');
        if (
            $row !== false && $this->matches($row, $version) && $row['state'] === 'completed'
            && is_string($row['completed_at'])
                && $row['completed_at'] >= $this->now()->modify('-1 hour')->format('Y-m-d H:i:s.u')
        ) {
            return $row;
        }
        if (
            $schedule && !($row !== false && $this->matches($row, $version) && in_array(
                $row['state'],
                ['running', 'capacity'],
                true
            ))
        ) {
            $this->schedule($version);
        }
        return null;
    }

    private function schedule(int $version): void
    {
        try {
            $this->db->transactional(function () use ($version): void {
                $row = $this->db->fetchAssociative('SELECT id, token, rules_version, origin_hash, state, '
                    . 'completed_at '
                    . 'FROM seo_sitemap_runs WHERE active_slot = 1 FOR UPDATE');
                if (
                    $row !== false && $this->matches($row, $version)
                    && ($row['state'] === 'running' || ($row['state'] === 'completed'
                        && is_string($row['completed_at'])
                        && $row['completed_at'] >= $this->now()->modify('-1 hour')->format('Y-m-d H:i:s.u')))
                ) {
                    return;
                }
                if ($row !== false) {
                    $this->db->update('seo_sitemap_runs', ['active_slot' => null], ['id' => $row['id']]);
                }
                $retained = $this->db->fetchFirstColumn('SELECT id FROM seo_sitemap_runs ORDER BY id LIMIT 33');
                if (count($retained) > 31) {
                    throw new RuntimeException('seo_sitemap_backlog');
                }
                $watermark = (int) ($this->db->fetchOne('SELECT MAX(id) FROM threads') ?: 0);
                $this->db->insert('seo_sitemap_runs', ['token' => bin2hex(random_bytes(16)),
                    'active_slot' => 1,
                    'rules_version' => $version, 'origin_hash' => $this->site->identity(), 'watermark'
                        => $watermark,
                    'created_at' => $this->now()->format('Y-m-d H:i:s.u')]);
                $id = (int) $this->db->lastInsertId();
                (new Outbox($this->db))->record('seo.sitemap_requested', 'sitemap', $id, ['format' => 1]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent requester published the one active generation. Retry by ordinary later request.
        }
    }

    /** Called inside the cooperative runner's lease-fenced transaction. False keeps the durable job pending. */
    public function build(OutboxLease $lease, Closure $guard): bool
    {
        if (
            !$this->db->isTransactionActive() || $lease->type !== 'seo.sitemap_requested'
            || $lease->aggregateId < 1
        ) {
            throw new RuntimeException('seo_sitemap_job_invalid');
        }
        $guard();
        $row = $this->db->fetchAssociative(
            'SELECT * FROM seo_sitemap_runs WHERE id = ? FOR UPDATE',
            [$lease->aggregateId]
        );
        if ($row === false || $row['state'] !== 'running' || $row['active_slot'] === null) {
            return true;
        }
        if (!$this->matches($row, $this->version())) {
            $this->db->update('seo_sitemap_runs', ['state' => 'obsolete', 'active_slot' => null], ['id'
                => $row['id']]);
            return true;
        }
        $ids = array_map(
            intval(...),
            $this->db->fetchFirstColumn(
                'SELECT id FROM threads WHERE id > ? AND id <= ? '
                . 'ORDER BY id LIMIT ' . self::BATCH,
                [(int) $row['cursor_id'], (int) $row['watermark']]
            )
        );
        if ($ids === []) {
            $this->db->update('seo_sitemap_runs', ['state' => 'completed',
                'completed_at' => $this->now()->format('Y-m-d H:i:s.u')], ['id' => $row['id']]);
            $guard();
            return true;
        }
        $eligible = $this->eligible($ids);
        $pages = (int) $row['page_count'];
        if ($eligible !== []) {
            if ($pages >= self::MAX_PAGES) {
                $this->db->update('seo_sitemap_runs', ['state' => 'capacity'], ['id' => $row['id']]);
                return true;
            }
            $this->db->insert('seo_sitemap_pages', ['run_id' => (int) $row['id'], 'ordinal' => ++$pages,
                'first_id' => $ids[0], 'last_id' => $ids[count($ids) - 1]]);
        }
        $this->db->update(
            'seo_sitemap_runs',
            ['cursor_id' => $ids[count($ids) - 1], 'page_count' => $pages],
            ['id' => $row['id']]
        );
        $guard();
        return false;
    }

    public function index(): ?string
    {
        $run = $this->active();
        if ($run === null) {
            return null;
        }
        $pages = $this->db->fetchFirstColumn('SELECT ordinal FROM seo_sitemap_pages WHERE run_id = ? '
            . 'ORDER BY ordinal LIMIT ' . (self::MAX_PAGES + 1), [(int) $run['id']]);
        if (count($pages) > self::MAX_PAGES) {
            throw new RuntimeException('seo_sitemap_capacity');
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        // Static public forum entry points are generated by the same route/owner-origin boundary.
        $xml .= '<sitemap><loc>' . self::escape($this->requiredUrl(CoreRoutes::SITEMAP_FORUMS)) . '</loc></sitemap>';
        foreach ($pages as $ordinal) {
            $url = $this->requiredUrl(CoreRoutes::SITEMAP_THREADS, ['generation' => $run['token'],
                'page' => (int) $ordinal]);
            $xml .= '<sitemap><loc>' . self::escape($url) . '</loc></sitemap>';
            if (strlen($xml) > 16777216) {
                throw new RuntimeException('seo_sitemap_capacity');
            }
        }
        return $this->matches($run, $this->version()) ? $xml . '</sitemapindex>' : null;
    }

    public function threads(string $token, int $ordinal): ?string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1 || $ordinal < 1 || $ordinal > self::MAX_PAGES) {
            return null;
        }
        $run = $this->active(false);
        if ($run === null || !hash_equals($run['token'], $token)) {
            return null;
        }
        $page = $this->db->fetchAssociative('SELECT first_id, last_id FROM seo_sitemap_pages WHERE run_id '
            . '= ? '
            . 'AND ordinal = ?', [(int) $run['id'], $ordinal]);
        if ($page === false) {
            return null;
        }
        $ids = array_map(
            intval(...),
            $this->db->fetchFirstColumn(
                'SELECT id FROM threads WHERE id >= ? AND id <= ? '
                . 'ORDER BY id LIMIT ' . (self::BATCH + 1),
                [(int) $page['first_id'], (int) $page['last_id']]
            )
        );
        if (count($ids) > self::BATCH) {
            throw new RuntimeException('seo_sitemap_changed');
        }
        $entries = [];
        $rules = $this->rules();
        foreach ($this->eligible($ids) as $thread) {
            if ($rules?->excludesThread((int) $thread['id'], (int) ($thread['forum_id'] ?? 0)) === true) {
                continue;
            }
            $entries[] = ['url' => $this->requiredUrl(CoreRoutes::THREAD_SHOW, ['slug' => (string) $thread['slug'],
                'thread' => (int) $thread['id']]),
                'modified' => (new DateTimeImmutable(
                    $thread['modified'],
                    new DateTimeZone('UTC')
                ))->format('c')];
        }
        return $this->matches($run, $this->version()) ? self::urlset($entries) : null;
    }

    public function forums(): ?string
    {
        if (!$this->site->configured()) {
            return null;
        }
        $version = $this->version();
        $scope = $this->visibility->visible(($this->guest)());
        if (count($scope->forumIds) > 10000) {
            throw new RuntimeException('seo_sitemap_forum_capacity');
        }
        $entries = [['url' => $this->requiredUrl(CoreRoutes::HOME), 'modified' => null]];
        $rules = $this->rules();
        foreach ($scope->forumIds as $forum) {
            if ($rules !== null && (!$rules->forums || $rules->excludesForum((int) $forum))) {
                continue;
            }
            $entries[] = ['url' => $this->requiredUrl(CoreRoutes::FORUM_SHOW, ['forum' => $forum]),
                'modified' => null];
        }

        // Tag pages (2026-11-08); a missing table (older schema) only leaves them out.
        try {
            $tags = $this->db->fetchFirstColumn('SELECT slug FROM tags ORDER BY id LIMIT 5000');
        } catch (Throwable) {
            $tags = [];
        }

        foreach ($rules?->tags === false ? [] : $tags as $slug) {
            $entries[] = ['url' => $this->requiredUrl(CoreRoutes::TAG_SHOW, ['tag' => (string) $slug]),
                'modified' => null];
        }

        // Extra addresses from extensions (2026-11-15): only on this site's own origin.
        $origin = $this->site->origin();
        foreach (array_slice($rules?->extraUrls ?? [], 0, 1000) as $url) {
            if ($origin !== null && is_string($url) && str_starts_with($url, $origin . '/') && strlen($url) <= 2000) {
                $entries[] = ['url' => $url, 'modified' => null];
            }
        }
        return $version === $this->version() ? self::urlset($entries) : null;
    }

    /** The first rules an enabled extension gives (2026-11-15); a failing extension changes nothing. */
    private function rules(): ?SitemapRules
    {
        foreach ($this->rules as $rules) {
            try {
                $result = $rules();
            } catch (Throwable) {
                continue;
            }
            if ($result instanceof SitemapRules) {
                return $result;
            }
        }

        return null;
    }

    public function collect(): void
    {
        $run = $this->db->fetchOne('SELECT id FROM seo_sitemap_runs WHERE active_slot IS NULL ORDER '
            . 'BY id LIMIT 1');
        if ($run === false) {
            return;
        }
        $pages = $this->db->fetchFirstColumn('SELECT ordinal FROM seo_sitemap_pages WHERE run_id = ? '
            . 'ORDER BY ordinal LIMIT 100', [(int) $run]);
        if ($pages !== []) {
            $this->db->executeStatement(
                'DELETE FROM seo_sitemap_pages WHERE run_id = ? AND ordinal IN (?)',
                [(int) $run, array_map(intval(...), $pages)],
                [ParameterType::INTEGER, ArrayParameterType::INTEGER]
            );
        } else {
            $this->db->delete('seo_sitemap_runs', ['id' => (int) $run, 'active_slot' => null]);
        }
    }

    private function eligible(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$scope, $parameters, $types] = ScopeSql::forums(
            $this->visibility->readable(($this->guest)()),
            't.forum_id'
        );
        return $this->db->fetchAllAssociative(
            'SELECT t.id, t.slug, t.forum_id, GREATEST(t.updated_at, '
            . 'COALESCE(t.last_post_at, t.updated_at)) AS modified FROM threads t INNER JOIN posts p '
            . 'ON p.id = t.first_post_id AND p.thread_id = t.id WHERE t.id IN (?) '
            . "AND t.status = 'visible' AND t.deleted_at IS NULL AND t.moved_to_thread_id IS NULL "
            . "AND p.status = 'visible' AND p.deleted_at IS NULL AND " . $scope . ' ORDER BY t.id LIMIT ' . self::BATCH,
            [$ids, ...$parameters],
            [ArrayParameterType::INTEGER, ...$types]
        );
    }

    private function matches(array $run, int $version): bool
    {
        return (int) $run['rules_version'] === $version && hash_equals(
            $run['origin_hash'],
            $this->site->identity()
        );
    }

    private function version(): int
    {
        return (new VersionCounter($this->db))->current(VersionCounter::PERMISSION_RULES);
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        ))();
    }

    private function requiredUrl(string $route, array $parameters = []): string
    {
        return $this->site->absolute($this->urls->generate($route, $parameters))
            ?? throw new RuntimeException('seo_origin_required');
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function urlset(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($entries as $entry) {
            $xml .= '<url><loc>' . self::escape($entry['url']) . '</loc>'
                . ($entry['modified'] === null ? '' : '<lastmod>' . self::escape($entry['modified']) . '</lastmod>')
                . '</url>';
        }
        return $xml . '</urlset>';
    }
}
