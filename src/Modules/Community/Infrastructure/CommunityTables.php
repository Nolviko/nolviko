<?php

declare(strict_types=1);

namespace Nolviko\Modules\Community\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/** Additive community records; private files remain outside the public tree. */
final class CommunityTables implements Migration
{
    public function id(): string
    {
        return '0040_community';
    }

    public function phase(): MigrationPhase
    {
        return MigrationPhase::Expand;
    }

    public function statements(string $backend): array
    {
        if ($backend !== 'mysql') {
            throw new MigrationException('migration_backend_unsupported:' . $backend);
        }

        return [
            "INSERT INTO usergroup_permissions (usergroup_id, permission_key, value) VALUES "
                . "(2, 'user.directory.view', '1'), (3, 'user.directory.view', '1'), "
                . "(4, 'user.directory.view', '1'), (3, 'moderation.profile.manage', '1'), "
                . "(4, 'moderation.profile.manage', '1')",

            'CREATE TABLE member_profiles (user_id BIGINT NOT NULL PRIMARY KEY, biography TEXT NOT '
                . 'NULL, location VARCHAR(120) NOT NULL, signature TEXT NOT NULL, visibility VARCHAR(16) '
                . 'NOT NULL DEFAULT \'everyone\', allow_messages SMALLINT NOT NULL DEFAULT 1, show_online '
                . 'SMALLINT NOT NULL DEFAULT 1, email_notifications SMALLINT NOT NULL DEFAULT 0, '
                . 'notify_replies SMALLINT NOT NULL DEFAULT 1, notify_reactions SMALLINT NOT NULL '
                . 'DEFAULT 1, notify_follows SMALLINT NOT NULL DEFAULT 1, CONSTRAINT fk_profiles_user_id '
                . 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT '
                . 'CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE member_activity (user_id BIGINT NOT NULL PRIMARY KEY, last_seen_at '
                . 'DATETIME(6) NOT NULL, INDEX ix_activity_recent (last_seen_at, user_id), CONSTRAINT '
                . 'fk_activity_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE member_blocks (user_id BIGINT NOT NULL, target_id BIGINT NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, PRIMARY KEY (user_id, target_id), INDEX '
                . 'ix_member_blocks_target (target_id, user_id), CONSTRAINT fk_member_blocks_user_id '
                . 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE, CONSTRAINT '
                . 'fk_member_blocks_target_id FOREIGN KEY (target_id) REFERENCES users (id) ON DELETE '
                . 'CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE member_follows (user_id BIGINT NOT NULL, target_id BIGINT NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, PRIMARY KEY (user_id, target_id), INDEX '
                . 'ix_member_follows_target (target_id, user_id), CONSTRAINT fk_member_follows_user_id '
                . 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE, CONSTRAINT '
                . 'fk_member_follows_target_id FOREIGN KEY (target_id) REFERENCES users (id) ON DELETE '
                . 'CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE member_bookmarks (user_id BIGINT NOT NULL, thread_id BIGINT NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, PRIMARY KEY (user_id, thread_id), INDEX '
                . 'ix_member_bookmarks_target (thread_id, user_id), CONSTRAINT '
                . 'fk_member_bookmarks_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE '
                . 'CASCADE, CONSTRAINT fk_member_bookmarks_thread_id FOREIGN KEY (thread_id) REFERENCES '
                . 'threads (id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 '
                . 'COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE thread_subscriptions (user_id BIGINT NOT NULL, thread_id BIGINT NOT '
                . 'NULL, email_optin SMALLINT NOT NULL DEFAULT 0, created_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (user_id, thread_id), INDEX ix_thread_subscriptions_target (thread_id, '
                . 'user_id), CONSTRAINT fk_thread_subscriptions_user_id FOREIGN KEY (user_id) REFERENCES '
                . 'users (id) ON DELETE CASCADE, CONSTRAINT fk_thread_subscriptions_thread_id FOREIGN '
                . 'KEY (thread_id) REFERENCES threads (id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT '
                . 'CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE reaction_types (id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT '
                . 'NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE post_reactions (user_id BIGINT NOT NULL, post_id BIGINT NOT NULL, '
                . 'type_id VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, created_at '
                . 'DATETIME(6) NOT NULL, PRIMARY KEY (user_id, post_id), INDEX ix_reactions_post '
                . '(post_id, type_id), CONSTRAINT fk_reactions_user_id FOREIGN KEY (user_id) REFERENCES '
                . 'users (id) ON DELETE CASCADE, CONSTRAINT fk_reactions_post_id FOREIGN KEY (post_id) '
                . 'REFERENCES posts (id) ON DELETE CASCADE, CONSTRAINT fk_reactions_type FOREIGN KEY '
                . '(type_id) REFERENCES reaction_types (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 '
                . 'COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE thread_polls (thread_id BIGINT NOT NULL PRIMARY KEY, user_id BIGINT '
                . 'NULL, question VARCHAR(240) NOT NULL, multiple_choice SMALLINT NOT NULL, closes_at '
                . 'DATETIME(6) NULL, CONSTRAINT fk_polls_thread_id FOREIGN KEY (thread_id) REFERENCES '
                . 'threads (id) ON DELETE CASCADE, CONSTRAINT fk_polls_user_id FOREIGN KEY (user_id) '
                . 'REFERENCES users (id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 '
                . 'COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE poll_options (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, thread_id '
                . 'BIGINT NOT NULL, label VARCHAR(160) NOT NULL, position SMALLINT NOT NULL, UNIQUE KEY '
                . 'uq_options_thread (thread_id, id), CONSTRAINT fk_options_poll FOREIGN KEY (thread_id) '
                . 'REFERENCES thread_polls (thread_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT '
                . 'CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE poll_ballots (user_id BIGINT NOT NULL, thread_id BIGINT NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, PRIMARY KEY (user_id, thread_id), CONSTRAINT '
                . 'fk_ballots_user_id FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_ballots_poll FOREIGN KEY (thread_id) REFERENCES thread_polls '
                . '(thread_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 '
                . 'COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE poll_votes (user_id BIGINT NOT NULL, thread_id BIGINT NOT NULL, '
                . 'option_id BIGINT NOT NULL, PRIMARY KEY (user_id, thread_id, option_id), INDEX '
                . 'ix_votes_option (thread_id, option_id), CONSTRAINT fk_votes_ballot FOREIGN KEY '
                . '(user_id, thread_id) REFERENCES poll_ballots (user_id, thread_id) ON DELETE CASCADE, '
                . 'CONSTRAINT fk_votes_option FOREIGN KEY (thread_id, option_id) REFERENCES poll_options '
                . '(thread_id, id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 '
                . 'COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE thread_solutions (thread_id BIGINT NOT NULL PRIMARY KEY, post_id BIGINT '
                . 'NOT NULL, user_id BIGINT NULL, created_at DATETIME(6) NOT NULL, CONSTRAINT '
                . 'fk_solutions_thread_id FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE '
                . 'CASCADE, CONSTRAINT fk_solutions_post_id FOREIGN KEY (post_id) REFERENCES posts (id) '
                . 'ON DELETE CASCADE, CONSTRAINT fk_solutions_user_id FOREIGN KEY (user_id) REFERENCES '
                . 'users (id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 '
                . 'COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE member_notifications (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'user_id BIGINT NOT NULL, actor_id BIGINT NULL, kind VARCHAR(24) NOT NULL, post_id '
                . 'BIGINT NULL, thread_id BIGINT NULL, dedupe_key VARCHAR(100) CHARACTER SET ascii '
                . 'COLLATE ascii_bin NOT NULL, read_at DATETIME(6) NULL, created_at DATETIME(6) NOT '
                . 'NULL, UNIQUE KEY uq_notification_dedupe (user_id, dedupe_key), INDEX '
                . 'ix_notifications_user (user_id, id), INDEX ix_notifications_unread (user_id, read_at, '
                . 'id), CONSTRAINT fk_notifications_user_id FOREIGN KEY (user_id) REFERENCES users (id) '
                . 'ON DELETE CASCADE, CONSTRAINT fk_notifications_actor_id FOREIGN KEY (actor_id) '
                . 'REFERENCES users (id) ON DELETE SET NULL, CONSTRAINT fk_notifications_post_id FOREIGN '
                . 'KEY (post_id) REFERENCES posts (id) ON DELETE CASCADE, CONSTRAINT '
                . 'fk_notifications_thread_id FOREIGN KEY (thread_id) REFERENCES threads (id) ON DELETE '
                . 'CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE community_fanout (event_id BIGINT NOT NULL PRIMARY KEY, cursor_id BIGINT '
                . 'NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE community_media (id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT '
                . 'NULL PRIMARY KEY, user_id BIGINT NULL, post_id BIGINT NULL, avatar_for BIGINT NULL, '
                . 'file_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, thumb_key CHAR(64) '
                . 'CHARACTER SET ascii COLLATE ascii_bin NULL, original_name VARCHAR(160) NOT NULL, mime '
                . 'VARCHAR(64) NOT NULL, bytes BIGINT NOT NULL, width INT NULL, height INT NULL, '
                . 'created_at DATETIME(6) NOT NULL, deleted_at DATETIME(6) NULL, INDEX ix_media_owner '
                . '(user_id, created_at), INDEX ix_media_post (post_id), INDEX ix_media_avatar '
                . '(avatar_for), CONSTRAINT fk_media_user_id FOREIGN KEY (user_id) REFERENCES users (id) '
                . 'ON DELETE SET NULL, CONSTRAINT fk_media_post_id FOREIGN KEY (post_id) REFERENCES '
                . 'posts (id) ON DELETE SET NULL, CONSTRAINT fk_media_avatar_for FOREIGN KEY '
                . '(avatar_for) REFERENCES users (id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT '
                . 'CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE community_mail_receipts (notification_id BIGINT NOT NULL PRIMARY KEY, '
                . 'sent_at DATETIME(6) NOT NULL, CONSTRAINT fk_mail_notification FOREIGN KEY '
                . '(notification_id) REFERENCES member_notifications (id) ON DELETE CASCADE) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'CREATE TABLE community_mail_budget (user_id BIGINT NOT NULL PRIMARY KEY, window_at '
                . 'DATETIME(6) NOT NULL, deliveries INT NOT NULL, CONSTRAINT fk_mail_budget_user_id '
                . 'FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT '
                . 'CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'INSERT INTO reaction_types (id) VALUES (\'like\'), (\'thanks\'), (\'insightful\')',
        ];
    }
}
