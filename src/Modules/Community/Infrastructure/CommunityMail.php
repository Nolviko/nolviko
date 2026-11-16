<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\SqlDialect;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Core\Mail\Mailer;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\UrlGenerator;

/** One generic notice per task, opt-in only, hourly bound. SMTP retries are at least once, never exactly once. */
final class CommunityMail
{
    public function __construct(
        private readonly Connection $db,
        private readonly Mailer $mailer,
        private readonly string $siteUrl,
        private readonly UrlGenerator $urls,
        private readonly CommunityMailTemplate $templates,
    ) {
    }

    public function deliver(OutboxLease $lease, Closure $guard): bool
    {
        $guard();
        $id = $lease->aggregateId;
        $row = $this->db->fetchAssociative('SELECT n.id, n.user_id, n.actor_id, n.kind, u.email, u.preferred_locale '
            . 'FROM member_notifications n '
            . 'INNER JOIN users u ON u.id = n.user_id INNER JOIN member_profiles p ON p.user_id = n.user_id '
            . "WHERE n.id = ? AND u.status = 'active' AND p.email_notifications = 1 "
            . "AND ((n.kind = 'follow' AND p.notify_follows = 1) "
            . "OR (n.kind = 'reaction' AND p.notify_reactions = 1) "
            . "OR (n.kind IN ('reply', 'mention') AND p.notify_replies = 1)) "
            . 'AND n.read_at IS NULL AND NOT EXISTS (SELECT 1 FROM member_blocks b WHERE '
            . '(b.user_id = n.user_id AND b.target_id = n.actor_id) '
            . 'OR (b.target_id = n.user_id AND b.user_id = n.actor_id))', [$id]);

        if (
            $row === false || $this->siteUrl === '' || $this->db->fetchOne(
                'SELECT notification_id FROM community_mail_receipts WHERE notification_id = ?',
                [$id],
            ) !== false
        ) {
            return true;
        }

        // No subject, username, thread identifier or snippet leaves the forum. The link rechecks all ACLs.
        $recipient = (int) $row['user_id'];
        $this->db->fetchOne('SELECT id FROM users WHERE id = ? FOR UPDATE', [$recipient]);
        $this->db->executeStatement(
            SqlDialect::upsert(
                $this->db,
                'INSERT INTO community_mail_budget (user_id, window_at, deliveries) VALUES (?, utc_timestamp(6), 0)',
                'community_mail_budget',
                ['user_id'],
                "deliveries = CASE WHEN @old.window_at < utc_timestamp(6) - INTERVAL '1' HOUR THEN 0 "
                    . 'ELSE @old.deliveries END, '
                    . "window_at = CASE WHEN @old.window_at < utc_timestamp(6) - INTERVAL '1' HOUR "
                    . 'THEN utc_timestamp(6) ELSE @old.window_at END',
            ),
            [$recipient]
        );
        $count = (int) $this->db->fetchOne(
            'SELECT deliveries FROM community_mail_budget WHERE user_id = ?',
            [$recipient]
        );

        if ($count >= 10) {
            return false;
        }

        $locale = str_starts_with((string) $row['preferred_locale'], 'tr') ? 'tr' : 'en';
        $url = rtrim($this->siteUrl, '/') . $this->urls->generate(
            CoreRoutes::COMMUNITY,
            ['section' => 'notifications']
        );
        $message = $this->templates->compose($locale, $row['email'], $url);
        $guard();
        $this->mailer->send($message);
        $this->db->insert('community_mail_receipts', ['notification_id' => $id,
            'sent_at' => CommunityInteractions::now()]);
        $this->db->executeStatement(
            'UPDATE community_mail_budget SET deliveries = deliveries + 1 WHERE user_id = ?',
            [$recipient]
        );

        return true;
    }
}
