<?php

declare(strict_types=1);

namespace Nolviko\Modules\Threads\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Modules\Threads\Domain\ThreadRuleViolation;

/**
 * Serializes the posts of one user and applies the posting rate limits (M03.05, M03.15). Every posting
 * transaction locks the user's row first, so parallel posts of one user run one after the other, and a repeated
 * form token meets the committed key of the first submit before any limit is checked.
 *
 * Limits are two RateBuckets per post: one per user and one per client network. prepare() creates them before
 * the transaction; assertWaited() hits them inside it.
 *
 * Lock order of all posting transactions: user row, idempotency key, user bucket, network bucket, thread row,
 * forum statistics row. lockUser() must run before the transaction's first plain read.
 */
final class PostingLock
{
    public const USER_BUCKET = 'post.user:';

    public const NETWORK_BUCKET = 'post.net:';

    private readonly RateBuckets $buckets;

    public function __construct(
        private readonly Connection $connection,
    ) {
        $this->buckets = new RateBuckets($connection);
    }

    /**
     * Outside any transaction: creates the buckets assertWaited() will use. Nothing to do for exempt users.
     */
    public function prepare(
        int $userId,
        int $intervalSeconds,
        ?string $network,
        int $networkSeconds,
        ?DateTimeImmutable $now = null,
    ): void {
        if ($intervalSeconds <= 0) {
            return;
        }

        $this->buckets->ensure(self::USER_BUCKET . $userId, $now);

        if ($network !== null && $networkSeconds > 0) {
            $this->buckets->ensure(self::NETWORK_BUCKET . $network, $now);
        }
    }

    public function lockUser(int $userId): void
    {
        $this->connection->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
    }

    /**
     * Call after lockUser() in the same transaction, with the arguments given to prepare(). A user interval of 0
     * is the per-group exemption (`forum.post.flood_interval_seconds` = 0, seeded for moderators and
     * administrators) and skips both limits.
     *
     * @param string|null $network ClientNetwork::key() of the request, null when unknown.
     *
     * @throws ThreadRuleViolation `post_flood_wait` with `seconds`.
     */
    public function assertWaited(
        int $userId,
        int $intervalSeconds,
        DateTimeImmutable $now,
        ?string $network = null,
        int $networkSeconds = 0,
    ): void {
        if ($intervalSeconds <= 0) {
            return;
        }

        $wait = $this->buckets->hit(self::USER_BUCKET . $userId, $intervalSeconds, $now);

        if ($wait === 0 && $network !== null && $networkSeconds > 0) {
            $wait = $this->buckets->hit(self::NETWORK_BUCKET . $network, $networkSeconds, $now);
        }

        if ($wait > 0) {
            throw new ThreadRuleViolation('post_flood_wait', ['seconds' => $wait]);
        }
    }
}
