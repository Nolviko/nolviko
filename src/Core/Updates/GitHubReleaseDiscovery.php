<?php

declare(strict_types=1);

namespace Nolviko\Core\Updates;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Nolviko\Core\Cache\Cache;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Explicit, best-effort release discovery: conditional GET, cached hints and bounded retry scheduling.
 * Nothing here is installed, downloaded or semver-selected, and normal forum rendering never invokes it.
 */
final class GitHubReleaseDiscovery
{
    public const CHECK_INTERVAL = 3600;

    public const FAILURE_BACKOFF = 300;

    public const MAX_BACKOFF = 21600;

    public const CACHE_RETENTION = 604800;

    public const MAX_RELEASES = 100;

    public const MAX_ASSETS_PER_RELEASE = 200;

    private const FAILURE_IDS = [
        'github_release_transport_failed',
        'github_release_http_failed',
        'github_release_response_invalid',
        'github_release_cache_missing',
    ];

    private ?string $memoryKey = null;

    /** @var array<string, mixed>|null */
    private ?array $memory = null;

    public function __construct(
        private readonly ReleaseHttpClient $client,
        private readonly Cache $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function check(
        ReleaseRepository $repository,
        string $channel,
        DateTimeImmutable $now,
        bool $force = false,
    ): ReleaseDiscoveryResult {
        if (
            !in_array($channel, ['stable', 'prerelease'], true)
            || $now->getTimestamp() < 0
            || $now->getTimestamp() > PHP_INT_MAX - ReleaseHttpResponse::MAX_RETRY_HINT - self::CACHE_RETENTION
        ) {
            throw new RuntimeException('github_discovery_policy_invalid');
        }

        $key = $repository->key();
        $state = $this->read($key);
        $catalog = [];
        $truncated = false;

        if ($state['body'] !== null) {
            try {
                $catalog = self::decode($state['body'], $truncated);
                $state['truncated'] = $truncated;
            } catch (Throwable) {
                $state = self::emptyState();
            }
        }

        $timestamp = $now->getTimestamp();

        if ($state['next_check'] > $timestamp && (!$force || $state['diagnostic'] !== null)) {
            return self::result($state, $catalog, $channel, $state['diagnostic'] === null ? 'cached' : 'stale');
        }

        $reason = 'github_release_transport_failed';
        $response = null;
        $status = 'fresh';

        try {
            $response = $this->client->fetch($repository, $state['body'] === null ? null : $state['etag']);
            $reason = 'github_release_http_failed';

            if ($response->status === 304) {
                $reason = 'github_release_cache_missing';

                if ($state['body'] === null) {
                    throw new RuntimeException($reason);
                }

                $status = 'not_modified';
                $state['etag'] = NativeReleaseHttpClient::safeEtag($response->etag) ?? $state['etag'];
            } elseif ($response->status === 200) {
                $reason = 'github_release_response_invalid';
                $catalog = self::decode($response->body, $truncated);
                $state['body'] = $response->body;
                $state['truncated'] = $truncated;
                $state['etag'] = NativeReleaseHttpClient::safeEtag($response->etag);
            } else {
                throw new RuntimeException($reason);
            }

            $state['checked_at'] = $timestamp;
            $state['last_success'] = $timestamp;
            $state['next_check'] = $timestamp + self::CHECK_INTERVAL;
            $state['failures'] = 0;
            $state['diagnostic'] = null;
        } catch (Throwable $failure) {
            $state['checked_at'] = $timestamp;
            $state['failures'] = min(16, $state['failures'] + 1);
            $delay = min(self::MAX_BACKOFF, self::FAILURE_BACKOFF * (2 ** min(10, $state['failures'] - 1)));
            $delay = max($delay, $response?->retryAfter ?? 0, ($response?->rateLimitReset ?? 0) - $timestamp);
            $state['next_check'] = $timestamp + min($delay, PHP_INT_MAX - $timestamp);
            $state['diagnostic'] = $reason;
            $status = $state['body'] === null ? 'unavailable' : 'stale';
            $this->warn($reason, ['exception_class' => $failure::class]);
        }

        $this->memoryKey = $key;
        $this->memory = $state;

        try {
            if (
                !$this->cache->set(
                    $key,
                    $state,
                    max(self::CACHE_RETENTION, $state['next_check'] - $timestamp + self::CHECK_INTERVAL),
                )
            ) {
                $this->warn('github_release_cache_unavailable');
            }
        } catch (Throwable) {
            $this->warn('github_release_cache_unavailable');
        }

        return self::result($state, $catalog, $channel, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $key): array
    {
        $value = null;

        try {
            $value = $this->cache->get($key);
        } catch (Throwable) {
            $this->warn('github_release_cache_unavailable');
        }

        if (!self::validState($value)) {
            $value = self::emptyState();
        }

        if (
            $this->memoryKey === $key
            && $this->memory !== null
            && $this->memory['checked_at'] >= $value['checked_at']
        ) {
            return $this->memory;
        }

        return $value;
    }

    private static function validState(mixed $state): bool
    {
        if (!is_array($state) || count($state) !== 9) {
            return false;
        }

        foreach (['checked_at', 'next_check', 'failures'] as $field) {
            if (!isset($state[$field]) || !is_int($state[$field]) || $state[$field] < 0) {
                return false;
            }
        }

        return array_key_exists('body', $state)
            && ($state['body'] === null || (is_string($state['body'])
                && strlen($state['body']) <= NativeReleaseHttpClient::MAX_BODY_BYTES))
            && array_key_exists('etag', $state)
            && ($state['etag'] === null || (is_string($state['etag'])
                && NativeReleaseHttpClient::safeEtag($state['etag']) !== null))
            && array_key_exists('last_success', $state)
            && ($state['last_success'] === null || (is_int($state['last_success']) && $state['last_success'] >= 0))
            && array_key_exists('diagnostic', $state)
            && ($state['diagnostic'] === null || in_array($state['diagnostic'], self::FAILURE_IDS, true))
            && $state['failures'] <= 16
            && isset($state['format'])
            && $state['format'] === 1
            && is_bool($state['truncated'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyState(): array
    {
        return [
            'format' => 1,
            'truncated' => false,
            'body' => null,
            'etag' => null,
            'checked_at' => 0,
            'last_success' => null,
            'next_check' => 0,
            'failures' => 0,
            'diagnostic' => null,
        ];
    }

    /**
     * @return list<DiscoveredRelease>
     */
    private static function decode(string $body, bool &$truncated): array
    {
        if (strlen($body) > NativeReleaseHttpClient::MAX_BODY_BYTES || !str_starts_with(ltrim($body), '[')) {
            throw new RuntimeException('github_release_response_invalid');
        }

        try {
            $rows = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('github_release_response_invalid');
        }

        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::MAX_RELEASES) {
            throw new RuntimeException('github_release_response_invalid');
        }

        $truncated = count($rows) === self::MAX_RELEASES;
        $releases = [];
        $ids = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !is_bool($row['draft'] ?? null) || !is_bool($row['prerelease'] ?? null)) {
                throw new RuntimeException('github_release_response_invalid');
            }

            if ($row['draft']) {
                continue;
            }

            $id = $row['id'] ?? null;
            $tag = $row['tag_name'] ?? null;
            $published = $row['published_at'] ?? null;

            if (
                !is_int($id)
                || $id <= 0
                || isset($ids[$id])
                || !is_string($tag)
                || $tag === ''
                || strlen($tag) > 200
                || !mb_check_encoding($tag, 'UTF-8')
                || preg_match('/[[:cntrl:]]/', $tag) === 1
                || !is_string($published)
                || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $published) !== 1
            ) {
                throw new RuntimeException('github_release_response_invalid');
            }

            $date = new DateTimeImmutable($published, new DateTimeZone('UTC'));
            $canonical = $date->format('Y-m-d') . 'T' . $date->format('H:i:s') . 'Z';

            if (
                $canonical !== $published || !is_array($row['assets'] ?? null)
                || !array_is_list($row['assets']) || count($row['assets']) > self::MAX_ASSETS_PER_RELEASE
            ) {
                throw new RuntimeException('github_release_response_invalid');
            }

            $assets = [];
            $assetIds = [];
            $assetNames = [];

            foreach ($row['assets'] as $asset) {
                if (!is_array($asset) || ($asset['state'] ?? null) !== 'uploaded') {
                    continue;
                }

                if (
                    !is_int($asset['id'] ?? null)
                    || $asset['id'] <= 0
                    || !is_string($asset['name'] ?? null)
                    || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,119}$/D', $asset['name']) !== 1
                    || !is_int($asset['size'] ?? null)
                    || $asset['size'] < 0
                    || $asset['size'] > SignedReleaseManifest::MAX_ARCHIVE_BYTES
                ) {
                    continue;
                }

                $nameKey = strtolower($asset['name']);

                if (isset($assetIds[$asset['id']]) || isset($assetNames[$nameKey])) {
                    throw new RuntimeException('github_release_response_invalid');
                }

                $assetIds[$asset['id']] = true;
                $assetNames[$nameKey] = true;
                $assets[] = ['id' => $asset['id'], 'name' => $asset['name'], 'bytes' => $asset['size']];
            }

            $ids[$id] = true;
            $releases[] = new DiscoveredRelease($id, $tag, $row['prerelease'], $date->getTimestamp(), $assets);
        }

        usort($releases, static fn(DiscoveredRelease $left, DiscoveredRelease $right): int
            => [$right->publishedAt, $right->id] <=> [$left->publishedAt, $left->id]);

        return $releases;
    }

    /**
     * @param array<string, mixed> $state
     * @param list<DiscoveredRelease> $catalog
     */
    private static function result(
        array $state,
        array $catalog,
        string $channel,
        string $status,
    ): ReleaseDiscoveryResult {
        $filtered = array_values(array_filter(
            $catalog,
            static fn(DiscoveredRelease $release): bool => $release->prerelease === ($channel === 'prerelease'),
        ));

        return new ReleaseDiscoveryResult(
            $filtered,
            $state['body'] === null && $state['diagnostic'] !== null ? 'unavailable' : $status,
            $state['diagnostic'],
            $state['checked_at'],
            $state['last_success'],
            $state['next_check'],
            $state['truncated'],
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function warn(string $diagnostic, array $context = []): void
    {
        try {
            $this->logger->warning($diagnostic, $context);
        } catch (Throwable) {
            // An unavailable diagnostic sink must not turn optional discovery into a failed forum request.
            return;
        }
    }
}
