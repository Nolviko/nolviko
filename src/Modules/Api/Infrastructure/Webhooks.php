<?php

declare(strict_types=1);

namespace Nolviko\Modules\Api\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\SqlDialect;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Modules\Permissions\Domain\ForumScope;
use Nolviko\Modules\Users\Domain\RegistrationRejected;
use Throwable;

/**
 * Outgoing webhooks (2026-11-08). Administrators register HTTPS endpoints (/admin/webhooks); when a thread or a
 * reply becomes visible in a forum guests can read, each active endpoint receives one JSON POST signed with its
 * secret (`X-Nolviko-Signature: sha256=<HMAC of the body>`). Delivery is once per event and endpoint
 * (`webhook_deliveries`), short timeouts, no redirects, and only to public addresses: an endpoint that resolves to
 * a private or reserved IP is never called. Nothing private (unreadable forums, pending posts, emails) is sent.
 */
final class Webhooks
{
    public const EVENTS = ['thread.created', 'post.created'];

    private const TIMEOUT = 4;

    /**
     * @param Closure(): ForumScope $guestScope
     * @param Closure(string, string, array<string, string>): int|null $send Test seam: URL, body, headers => status.
     */
    public function __construct(
        private readonly Connection $db,
        private readonly Closure $guestScope,
        private readonly string $siteUrl,
        private readonly ?Closure $send = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        try {
            return $this->db->fetchAllAssociative('SELECT id, url, events, active, failures, last_status, last_at '
                . 'FROM webhooks ORDER BY id LIMIT 50');
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param list<string> $events
     * @return string The new secret, shown once.
     *
     * @throws RegistrationRejected `webhook_url_invalid` or `webhook_events_invalid`.
     */
    public function add(string $url, array $events): string
    {
        $url = trim($url);
        $events = array_values(array_intersect(self::EVENTS, $events));

        if (strlen($url) > 500 || !self::publicHttps($url, false)) {
            throw new RegistrationRejected(['webhook_url_invalid']);
        }

        if ($events === []) {
            throw new RegistrationRejected(['webhook_events_invalid']);
        }

        $secret = bin2hex(random_bytes(24));
        $this->db->insert('webhooks', ['url' => $url, 'secret' => $secret, 'events' => implode(',', $events),
            'active' => 1, 'failures' => 0, 'created_at' => gmdate('Y-m-d H:i:s')]);

        return $secret;
    }

    public function delete(int $id): void
    {
        $this->db->delete('webhooks', ['id' => $id]);
    }

    /**
     * Delivers one outbox event to every interested endpoint; never throws (a webhook is best effort).
     */
    public function deliver(OutboxLease $lease): void
    {
        try {
            $hooks = $this->db->fetchAllAssociative('SELECT id, url, secret, events FROM webhooks WHERE active = 1');
        } catch (Throwable) {
            return;
        }

        $hooks = array_filter($hooks, static fn(array $hook): bool => in_array(
            $lease->type,
            explode(',', (string) $hook['events']),
            true,
        ));

        if ($hooks === []) {
            return;
        }

        $payload = $this->payload($lease);

        if ($payload === null) {
            return;
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        foreach ($hooks as $hook) {
            if (
                $this->db->executeStatement(
                    SqlDialect::insertIgnore(
                        $this->db,
                        'INSERT INTO webhook_deliveries (event_id, webhook_id, created_at) VALUES (?, ?, ?)',
                    ),
                    [$lease->id, $hook['id'], gmdate('Y-m-d H:i:s')],
                ) !== 1
            ) {
                continue;
            }

            $status = self::publicHttps((string) $hook['url'], true) ? ($this->send ?? self::post(...))(
                (string) $hook['url'],
                $body,
                ['Content-Type' => 'application/json', 'User-Agent' => 'Nolviko-Webhook/1',
                    'X-Nolviko-Event' => $lease->type,
                    'X-Nolviko-Signature' => 'sha256=' . hash_hmac('sha256', $body, (string) $hook['secret'])],
            ) : null;
            $ok = $status !== null && $status >= 200 && $status < 300;
            $this->db->executeStatement(
                'UPDATE webhooks SET last_status = ?, last_at = ?, failures = CASE WHEN ? = 1 THEN 0 '
                    . 'ELSE failures + 1 END, active = CASE WHEN failures >= 50 THEN 0 ELSE active END WHERE id = ?',
                [$status, gmdate('Y-m-d H:i:s'), (int) $ok, $hook['id']],
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function payload(OutboxLease $lease): ?array
    {
        $data = json_decode($lease->payload, true, 8);
        $postId = is_array($data) && is_int($data['post_id'] ?? null) ? $data['post_id'] : null;

        if ($postId === null) {
            return null;
        }

        $row = $this->db->fetchAssociative(
            'SELECT p.id, p.thread_id, p.created_at, t.forum_id, t.title, t.slug, u.username FROM posts p '
                . 'INNER JOIN threads t ON t.id = p.thread_id LEFT JOIN users u ON u.id = p.user_id '
                . "WHERE p.id = ? AND p.status = 'visible' AND t.status = 'visible' AND t.moved_to_thread_id IS NULL",
            [$postId],
        );

        if ($row === false || !($this->guestScope)()->contains((int) $row['forum_id'])) {
            return null;
        }

        $base = rtrim($this->siteUrl, '/');

        return ['event' => $lease->type, 'thread' => ['id' => (int) $row['thread_id'], 'title' => $row['title'],
            'forum_id' => (int) $row['forum_id'],
            'url' => $base . '/threads/' . $row['slug'] . '-' . $row['thread_id']],
            'post' => ['id' => (int) $row['id'], 'author' => $row['username'],
                'created_at' => gmdate(DATE_ATOM, (int) strtotime($row['created_at'] . ' UTC')),
                'url' => $base . '/posts/' . $row['id']]];
    }

    /** An https URL whose host resolves only to public addresses (resolution skipped when $resolve is false). */
    public static function publicHttps(string $url, bool $resolve): bool
    {
        $parts = parse_url($url);

        if (
            !is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            return false;
        }

        $host = trim($parts['host'], '[]');

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::publicIp($host);
        }

        if (!$resolve) {
            return !in_array(strtolower($host), ['localhost'], true) && str_contains($host, '.');
        }

        $addresses = @gethostbynamel($host);

        return is_array($addresses) && $addresses !== []
            && array_filter($addresses, static fn(string $ip): bool => !self::publicIp($ip)) === [];
    }

    private static function publicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @param array<string, string> $headers */
    private static function post(string $url, string $body, array $headers): ?int
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $lines),
            'content' => $body, 'timeout' => self::TIMEOUT, 'follow_location' => 0, 'max_redirects' => 0,
            'ignore_errors' => true]]);
        $stream = @fopen($url, 'rb', false, $context);

        if ($stream === false) {
            return null;
        }

        $meta = stream_get_meta_data($stream);
        fclose($stream);
        $status = is_array($meta['wrapper_data'] ?? null) ? (string) ($meta['wrapper_data'][0] ?? '') : '';

        return preg_match('~^HTTP/\S+\s+(\d{3})~', $status, $match) === 1 ? (int) $match[1] : null;
    }
}
