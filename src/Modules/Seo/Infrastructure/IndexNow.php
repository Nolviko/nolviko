<?php

declare(strict_types=1);

namespace Nolviko\Modules\Seo\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Throwable;

/**
 * IndexNow (2026-11-08, site setting `seo.indexnow`, off by default): when a thread becomes visible in a forum
 * guests can read, its address is sent to api.indexnow.org, which shares it with Bing, Yandex and the other
 * participating engines. The verification key is derived from the app secret and served at /indexnow.txt.
 * Best effort: one short request per new thread, never retried, never blocks anything.
 */
final readonly class IndexNow
{
    private const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /**
     * @param Closure(): ForumScope $guestScope
     * @param Closure(string): ?int|null $send Test seam: URL => HTTP status.
     */
    public function __construct(
        private Connection $db,
        private Closure $guestScope,
        private string $siteUrl,
        private string $key,
        private ?Closure $send = null,
    ) {
    }

    /** The verification key for an app secret: 32 hex characters. */
    public static function key(string $appSecret): string
    {
        return substr(hash_hmac('sha256', 'indexnow', $appSecret), 0, 32);
    }

    public function submit(OutboxLease $lease): void
    {
        try {
            if (
                $this->siteUrl === '' || ($this->db->fetchOne(
                    "SELECT setting_value FROM settings WHERE setting_key = 'seo.indexnow'",
                ) ?: 'off') !== 'on'
            ) {
                return;
            }

            $data = json_decode($lease->payload, true, 8);
            $thread = is_array($data) && is_int($data['thread_id'] ?? null) ? $data['thread_id'] : $lease->aggregateId;
            $row = $this->db->fetchAssociative(
                "SELECT forum_id, slug FROM threads WHERE id = ? AND status = 'visible' AND moved_to_thread_id IS NULL",
                [$thread],
            );

            if ($row === false || !($this->guestScope)()->contains((int) $row['forum_id'])) {
                return;
            }

            $base = rtrim($this->siteUrl, '/');
            // The canonical `/threads/{slug}-{id}` address (2026-11-14).
            $url = self::ENDPOINT . '?' . http_build_query(['url' => $base . '/threads/' . $row['slug'] . '-' . $thread,
                'key' => $this->key, 'keyLocation' => $base . '/indexnow.txt']);
            ($this->send ?? self::get(...))($url);
        } catch (Throwable) {
            // Search engine notice only.
        }
    }

    private static function get(string $url): ?int
    {
        $agent = 'User-Agent: Nolviko-IndexNow/1'; // i18n-ignore: HTTP header.
        $stream = @fopen($url, 'rb', false, stream_context_create(['http' => ['method' => 'GET', 'timeout' => 4,
            'follow_location' => 0, 'ignore_errors' => true, 'header' => $agent]]));

        if ($stream === false) {
            return null;
        }

        $meta = stream_get_meta_data($stream);
        fclose($stream);
        $line = is_array($meta['wrapper_data'] ?? null) ? (string) ($meta['wrapper_data'][0] ?? '') : '';

        return preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $match) === 1 ? (int) $match[1] : null;
    }
}
