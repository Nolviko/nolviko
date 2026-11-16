<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Core\Session\Session;
use Throwable;

/**
 * Thread view counts (2026-11-08). One `UPDATE ... + 1` per counted view of a thread page; no cron, no buffer
 * table. Not counted: crawlers and link previews (User-Agent), browser prefetch, and a member opening the same
 * thread again in one session (the last SEEN_LIMIT thread IDs are kept in the session). Guests get no session
 * for this, so their pages stay render-cacheable; each guest page view counts. A failed write never breaks the
 * page: the number is a statistic, not data.
 */
final readonly class ThreadViews
{
    private const SEEN_KEY = '_threads_seen';

    private const SEEN_LIMIT = 50;

    private const BOTS = '~bot|crawl|spider|slurp|preview|facebookexternalhit|embedly|curl|wget|python-requests'
        . '|httpclient|monitor|lighthouse|headless~i';

    public function __construct(
        private Connection $db,
        private Session $session,
    ) {
    }

    /**
     * @param string $userAgent The request's User-Agent header ('' when absent).
     * @param string $purpose The Sec-Purpose or Purpose header ('' when absent).
     */
    public function record(string $userAgent, string $purpose, int $threadId, ?int $userId): void
    {
        if (!self::counts($userAgent, $purpose)) {
            return;
        }

        if ($userId !== null) {
            $seen = $this->session->get(self::SEEN_KEY);
            $seen = is_array($seen) ? array_values(array_filter($seen, 'is_int')) : [];

            if (in_array($threadId, $seen, true)) {
                return;
            }

            $seen[] = $threadId;
            $this->session->set(self::SEEN_KEY, array_slice($seen, -self::SEEN_LIMIT));
        }

        try {
            $this->db->executeStatement(
                'UPDATE threads SET view_count = view_count + 1 WHERE id = ?',
                [$threadId],
                [ParameterType::INTEGER],
            );
        } catch (Throwable) {
            // A statistic: never fail the page over it.
        }
    }

    public static function counts(string $userAgent, string $purpose): bool
    {
        return $userAgent !== '' && preg_match(self::BOTS, $userAgent) !== 1
            && !str_contains(strtolower($purpose), 'prefetch');
    }
}
