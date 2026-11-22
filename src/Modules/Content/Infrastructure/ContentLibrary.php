<?php

declare(strict_types=1);

namespace Nolviko\Modules\Content\Infrastructure;

use Closure;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nolviko\Core\Cache\Cache;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Modules\Content\Domain\ContentRejected;
use Nolviko\Modules\Editor\Domain\BbcodeParser;
use Nolviko\Modules\Editor\Domain\CustomTag;
use Nolviko\Modules\Editor\Domain\CustomTagRejected;
use Nolviko\Modules\Editor\Domain\RenderAddons;
use Throwable;

/**
 * Smileys, thread/post icons and MyCode (ADR-042). Administrators change them (audited, fresh admin session); every
 * change bumps `content.addons_version` and drops one cache entry. Readers get the whole set from that cache entry
 * (one file read, no query) for rendering, cache keys and icon lists, so hot pages pay nothing per row.
 *
 * Importers map a source forum's codes onto these smileys: a smiley's aliases are extra codes for the same image,
 * and importSmiley() adds a source image or merges its code into an existing smiley as an alias.
 */
final class ContentLibrary
{
    public const VERSION_KEY = 'content.addons_version';

    public const MAX_SMILEYS = 300;

    public const MAX_ICONS = 100;

    public const MAX_BBCODES = 50;

    private const CACHE_KEY = 'content-addons-v1';

    private const TTL = 2592000;

    private const CODE = '/^[^\s<>"\'&\[\]]{2,30}$/Du';

    /** @var array<string, mixed>|null */
    private ?array $set = null;

    /**
     * @param Closure(): Connection $connection Opened only when the cache misses or on a change.
     * @param Closure(string, string): string $imageUrl Image key and extension => public URL.
     */
    public function __construct(
        private readonly Closure $connection,
        private readonly Cache $cache,
        private readonly SiteImages $images,
        private readonly Closure $imageUrl,
    ) {
    }

    /** The renderer's view of the set; MyCode that no longer compiles is skipped (never trusted from storage). */
    public function addons(): RenderAddons
    {
        $set = $this->set();
        $tags = [];

        foreach ($set['tags'] as $row) {
            try {
                $tags[$row['tag']] = CustomTag::compile($row['tag'], $row['parameter'], $row['template']);
            } catch (CustomTagRejected) {
                continue;
            }
        }

        return new RenderAddons((int) $set['version'], $set['smileys'], $tags);
    }

    /** @return array<int, array{id: int, url: string, title: string, width: int, height: int}> id => icon */
    public function icons(): array
    {
        return $this->set()['icons'];
    }

    /** @return array{id: int, url: string, title: string, width: int, height: int}|null */
    public function icon(?int $id): ?array
    {
        return $id === null ? null : ($this->icons()[$id] ?? null);
    }

    /**
     * Icons of listed threads (roadmap 29). No query while the library has no icons, so boards without icons pay
     * nothing on forum pages.
     *
     * @param list<int> $threadIds
     * @return array<int, array{id: int, url: string, title: string, width: int, height: int}> thread ID => icon
     */
    public function threadIcons(array $threadIds): array
    {
        $icons = $this->icons();

        if ($icons === [] || $threadIds === []) {
            return [];
        }

        try {
            $rows = $this->db()->fetchAllKeyValue(
                'SELECT id, icon_id FROM threads WHERE id IN (?) AND icon_id IS NOT NULL',
                [$threadIds],
                [ArrayParameterType::INTEGER],
            );
        } catch (Throwable) {
            return [];
        }

        $result = [];

        foreach ($rows as $thread => $icon) {
            if (isset($icons[(int) $icon])) {
                $result[(int) $thread] = $icons[(int) $icon];
            }
        }

        return $result;
    }

    /** A submitted icon choice: null for none or unknown. */
    public function pickIcon(mixed $value): ?int
    {
        $id = is_string($value) && ctype_digit($value) ? (int) $value : 0;

        return $id > 0 && isset($this->icons()[$id]) ? $id : null;
    }

    /** @return list<array<string, mixed>> Smileys for the admin page and the editor's smiley list. */
    public function smileys(): array
    {
        return $this->set()['smiley_rows'];
    }

    /** @return list<array<string, mixed>> */
    public function bbcodes(): array
    {
        return array_map(
            static fn(array $row): array => $row + ['pattern' => '[' . $row['tag']
            . ($row['parameter'] === 'none' ? '' : '={param}') . ']{content}[/' . $row['tag'] . ']'],
            $this->db()->fetchAllAssociative('SELECT id, tag, parameter, template, description, enabled '
            . 'FROM custom_bbcodes ORDER BY tag')
        );
    }

    /**
     * @throws ContentRejected `content_code_invalid`, `content_code_taken`, `content_title_invalid`, image errors.
     */
    public function addSmiley(string $code, string $aliases, string $title, string $bytes, ?int $actor): int
    {
        $codes = self::codes($code, $aliases);
        $title = self::title($title);
        $image = $this->images->store($bytes);

        try {
            return $this->change(function (Connection $db) use ($codes, $title, $image, $actor): int {
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM smileys') >= self::MAX_SMILEYS) {
                    throw new ContentRejected('content_limit', ['max' => (string) self::MAX_SMILEYS]);
                }

                $this->assertCodesFree($db, $codes, null);
                $db->insert('smileys', ['code' => $codes[0], 'aliases' => implode(' ', array_slice($codes, 1)) ?: null,
                    'title' => $title, 'image_key' => $image['key'], 'mime' => $image['mime'],
                    'width' => $image['width'], 'height' => $image['height'],
                    'position' => (int) $db->fetchOne('SELECT COALESCE(MAX(position), 0) + 1 FROM smileys'),
                    'created_at' => self::now()]);
                $id = (int) $db->lastInsertId();
                $this->audit($db, $actor, 'content.smiley_added', 'smiley', $id, ['code' => $codes[0]]);

                return $id;
            });
        } catch (Throwable $failure) {
            $this->images->remove($image['key']);

            throw $failure;
        }
    }

    /**
     * Importer entry point: a source code that already exists stays as it is; a source image whose code is new is
     * added; `$mapTo` merges the source code into an existing smiley as an alias instead.
     *
     * @return int The smiley that now answers to `$code`.
     */
    public function importSmiley(string $code, string $title, ?string $bytes, ?string $mapTo = null): int
    {
        $codes = self::codes($code, '');
        $existing = $this->db()->fetchAllAssociative('SELECT id, code, aliases FROM smileys');

        foreach ($existing as $row) {
            $known = array_merge([(string) $row['code']], self::split((string) ($row['aliases'] ?? '')));

            if (in_array($codes[0], $known, true)) {
                return (int) $row['id'];
            }

            if ($mapTo !== null && in_array($mapTo, $known, true)) {
                $this->updateSmiley((int) $row['id'], (string) $row['code'], implode(' ', array_merge(
                    array_slice($known, 1),
                    [$codes[0]],
                )), null, null);

                return (int) $row['id'];
            }
        }

        if ($bytes === null) {
            throw new ContentRejected('content_image_invalid', ['kb' => (string) (SiteImages::MAX_BYTES / 1024)]);
        }

        return $this->addSmiley($codes[0], '', $title, $bytes, null);
    }

    public function updateSmiley(int $id, string $code, string $aliases, ?string $title, ?int $actor): void
    {
        $codes = self::codes($code, $aliases);
        $this->change(function (Connection $db) use ($id, $codes, $title, $actor): void {
            $this->assertCodesFree($db, $codes, $id);
            $values = ['code' => $codes[0], 'aliases' => implode(' ', array_slice($codes, 1)) ?: null];

            if ($title !== null) {
                $values['title'] = self::title($title);
            }

            if ($db->update('smileys', $values, ['id' => $id]) > 0) {
                $this->audit($db, $actor, 'content.smiley_changed', 'smiley', $id, ['code' => $codes[0]]);
            }
        });
    }

    public function deleteSmiley(int $id, int $actor): void
    {
        $this->deleteImageRow('smileys', 'smiley', $id, $actor);
    }

    public function addIcon(string $title, string $bytes, int $actor): int
    {
        $title = self::title($title);
        $image = $this->images->store($bytes);

        try {
            return $this->change(function (Connection $db) use ($title, $image, $actor): int {
                if ((int) $db->fetchOne('SELECT COUNT(*) FROM content_icons') >= self::MAX_ICONS) {
                    throw new ContentRejected('content_limit', ['max' => (string) self::MAX_ICONS]);
                }

                $db->insert('content_icons', ['title' => $title, 'image_key' => $image['key'], 'mime' => $image['mime'],
                    'width' => $image['width'], 'height' => $image['height'],
                    'position' => (int) $db->fetchOne('SELECT COALESCE(MAX(position), 0) + 1 FROM content_icons'),
                    'created_at' => self::now()]);
                $id = (int) $db->lastInsertId();
                $this->audit($db, $actor, 'content.icon_added', 'icon', $id, ['title' => $title]);

                return $id;
            });
        } catch (Throwable $failure) {
            $this->images->remove($image['key']);

            throw $failure;
        }
    }

    /** Threads and posts keep the stale ID; an unknown icon is simply not shown (no rewrite of large tables). */
    public function deleteIcon(int $id, int $actor): void
    {
        $this->deleteImageRow('content_icons', 'icon', $id, $actor);
    }

    /**
     * Validates by compiling (ADR-042) before anything is written.
     *
     * @throws ContentRejected `content_limit`, `mycode_tag_taken` or a `mycode_*` compile diagnostic.
     */
    public function saveBbcode(
        ?int $id,
        string $pattern,
        string $parameter,
        string $template,
        string $description,
        bool $enabled,
        int $actor,
    ): int {
        try {
            [$tag, $hasParameter] = CustomTag::pattern($pattern);

            if ($hasParameter === ($parameter === 'none')) {
                throw new CustomTagRejected('mycode_parameter_invalid');
            }

            CustomTag::compile($tag, $parameter, str_replace("\r\n", "\n", $template));
        } catch (CustomTagRejected $rejected) {
            throw new ContentRejected($rejected->diagnostic, $rejected->parameters);
        }

        $description = mb_substr(trim($description), 0, 200);
        $values = ['tag' => $tag, 'parameter' => $parameter, 'template' => str_replace("\r\n", "\n", $template),
            'description' => $description === '' ? null : $description, 'enabled' => $enabled ? 1 : 0,
            'updated_at' => self::now()];

        try {
            return $this->change(function (Connection $db) use ($id, $values, $actor, $tag): int {
                if ($id === null) {
                    if ((int) $db->fetchOne('SELECT COUNT(*) FROM custom_bbcodes') >= self::MAX_BBCODES) {
                        throw new ContentRejected('content_limit', ['max' => (string) self::MAX_BBCODES]);
                    }

                    $db->insert('custom_bbcodes', $values + ['created_at' => $values['updated_at']]);
                    $id = (int) $db->lastInsertId();
                } elseif ($db->update('custom_bbcodes', $values, ['id' => $id]) === 0) {
                    throw new ContentRejected('content_not_found');
                }

                $this->audit($db, $actor, 'content.mycode_saved', 'mycode', $id, ['tag' => $tag]);

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ContentRejected('mycode_tag_taken');
        }
    }

    public function deleteBbcode(int $id, int $actor): void
    {
        $this->change(function (Connection $db) use ($id, $actor): void {
            if ($db->delete('custom_bbcodes', ['id' => $id]) === 1) {
                $this->audit($db, $actor, 'content.mycode_deleted', 'mycode', $id, []);
            }
        });
    }

    /** Removes the cached set (after a change, or from the cache tools). */
    public function forget(): void
    {
        $this->set = null;
        $this->cache->delete(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    private function set(): array
    {
        if ($this->set !== null) {
            return $this->set;
        }

        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['version'], $cached['smileys'], $cached['tags'], $cached['icons'])) {
            return $this->set = $cached;
        }

        try {
            $set = $this->load($this->db());
        } catch (Throwable) {
            // An unreadable database renders plain BBCode; nothing is cached, so the next request tries again.
            return $this->set = ['version' => 0, 'smileys' => [], 'smiley_rows' => [], 'tags' => [], 'icons' => []];
        }

        $this->cache->set(self::CACHE_KEY, $set, self::TTL);

        return $this->set = $set;
    }

    /** @return array<string, mixed> */
    private function load(Connection $db): array
    {
        $smileys = [];
        $rows = [];

        foreach (
            $db->fetchAllAssociative('SELECT id, code, aliases, title, image_key, mime, width, height FROM smileys '
                . 'ORDER BY position, id') as $row
        ) {
            $url = ($this->imageUrl)((string) $row['image_key'], SiteImages::TYPES[(string) $row['mime']] ?? 'png');
            $codes = array_merge([(string) $row['code']], self::split((string) ($row['aliases'] ?? '')));
            $smileys[] = ['codes' => $codes, 'url' => $url, 'title' => (string) $row['title'],
                'width' => (int) $row['width'], 'height' => (int) $row['height']];
            $rows[] = ['id' => (int) $row['id'], 'code' => (string) $row['code'],
                'aliases' => (string) ($row['aliases'] ?? ''), 'title' => (string) $row['title'], 'url' => $url,
                'width' => (int) $row['width'], 'height' => (int) $row['height']];
        }

        $icons = [];

        foreach (
            $db->fetchAllAssociative('SELECT id, title, image_key, mime, width, height FROM content_icons '
                . 'ORDER BY position, id') as $row
        ) {
            $icons[(int) $row['id']] = ['id' => (int) $row['id'], 'title' => (string) $row['title'],
                'url' => ($this->imageUrl)(
                    (string) $row['image_key'],
                    SiteImages::TYPES[(string) $row['mime']] ?? 'png',
                ),
                'width' => (int) $row['width'], 'height' => (int) $row['height']];
        }

        $tags = array_map(
            static fn(array $row): array => ['tag' => (string) $row['tag'],
            'parameter' => (string) $row['parameter'], 'template' => (string) $row['template']],
            $db->fetchAllAssociative('SELECT tag, parameter, template FROM custom_bbcodes WHERE enabled = 1 '
            . 'ORDER BY tag')
        );

        return ['version' => (new VersionCounter($db))->current(self::VERSION_KEY), 'smileys' => $smileys,
            'smiley_rows' => $rows, 'tags' => $tags, 'icons' => $icons];
    }

    /**
     * Runs a change with the version bump in one transaction, then drops the cached set.
     *
     * @template T
     * @param Closure(Connection): T $change
     * @return T
     */
    private function change(Closure $change): mixed
    {
        $db = $this->db();

        try {
            return $db->transactional(static function (Connection $db) use ($change): mixed {
                $result = $change($db);
                VersionCounter::bump($db, self::VERSION_KEY);

                return $result;
            });
        } finally {
            $this->forget();
        }
    }

    private function deleteImageRow(string $table, string $kind, int $id, int $actor): void
    {
        $key = $this->change(function (Connection $db) use ($table, $kind, $id, $actor): ?string {
            $key = $db->fetchOne('SELECT image_key FROM ' . $table . ' WHERE id = ?', [$id]);

            if ($key === false) {
                return null;
            }

            $db->delete($table, ['id' => $id]);
            $this->audit($db, $actor, 'content.' . $kind . '_deleted', $kind, $id, []);

            return (string) $key;
        });

        if ($key !== null) {
            $this->images->remove($key);
        }
    }

    /** @param list<string> $codes */
    private function assertCodesFree(Connection $db, array $codes, ?int $except): void
    {
        foreach ($db->fetchAllAssociative('SELECT id, code, aliases FROM smileys') as $row) {
            if ((int) $row['id'] === $except) {
                continue;
            }

            $known = array_merge([(string) $row['code']], self::split((string) ($row['aliases'] ?? '')));

            if (array_intersect($codes, $known) !== []) {
                throw new ContentRejected('content_code_taken');
            }
        }
    }

    /** @return list<string> Main code first; at most nine aliases. */
    private static function codes(string $code, string $aliases): array
    {
        $codes = array_values(array_unique(array_merge([trim($code)], self::split($aliases))));

        if (count($codes) > 10 || strlen(implode(' ', array_slice($codes, 1))) > 200) {
            throw new ContentRejected('content_code_invalid');
        }

        foreach ($codes as $one) {
            if (
                preg_match(self::CODE, $one) !== 1
                || in_array(strtolower(trim($one, '[]')), BbcodeParser::TAGS, true)
            ) {
                throw new ContentRejected('content_code_invalid');
            }
        }

        return $codes;
    }

    /** @return list<string> */
    private static function split(string $aliases): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($aliases)) ?: [], static fn($s): bool => $s !== ''));
    }

    private static function title(string $title): string
    {
        $title = trim($title);

        if (
            $title === '' || mb_strlen($title) > 60 || !mb_check_encoding($title, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f]/', $title) === 1
        ) {
            throw new ContentRejected('content_title_invalid');
        }

        return $title;
    }

    /** @param array<string, mixed> $details */
    private function audit(Connection $db, ?int $actor, string $action, string $type, int $id, array $details): void
    {
        $db->insert('audit_logs', ['actor_id' => $actor, 'action' => $action, 'target_type' => $type,
            'target_id' => $id, 'reason' => null, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => self::now()]);
    }

    private function db(): Connection
    {
        return ($this->connection)();
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s.u');
    }
}
