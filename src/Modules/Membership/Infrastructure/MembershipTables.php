<?php

declare(strict_types=1);

namespace Nolviko\Modules\Membership\Infrastructure;

use Nolviko\Core\Database\Migration\Migration;
use Nolviko\Core\Database\Migration\MigrationException;
use Nolviko\Core\Database\Migration\MigrationPhase;

/**
 * Membership features of the beta.18 roadmap (group G3): member options (date and time format, theme, birthday,
 * group mail), accepted membership terms, registration questions, group applications and leaders, group mail jobs,
 * timed memberships (ADR-039), promotion rules, required and choice profile fields. Expand only: new tables and one
 * new column, so an unattended update can apply it.
 */
final class MembershipTables implements Migration
{
    public const ID = '0070_membership';

    private const OPTIONS = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    private const ASCII = ' CHARACTER SET ascii COLLATE ascii_bin';

    public function id(): string
    {
        return self::ID;
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

        $user = static fn(string $name, string $column = 'user_id'): string => 'CONSTRAINT ' . $name
            . ' FOREIGN KEY (' . $column . ') REFERENCES users (id) ON DELETE CASCADE';
        $group = static fn(string $name): string => 'CONSTRAINT ' . $name
            . ' FOREIGN KEY (usergroup_id) REFERENCES usergroups (id) ON DELETE CASCADE';

        return [
            'CREATE TABLE member_options (user_id BIGINT NOT NULL PRIMARY KEY, date_format VARCHAR(8)' . self::ASCII
                . ' NULL, time_format VARCHAR(4)' . self::ASCII . ' NULL, site_theme VARCHAR(64)' . self::ASCII
                . ' NULL, birth_date DATE NULL, birth_hide_year TINYINT(1) NOT NULL DEFAULT 0, '
                . 'birth_md SMALLINT NULL, group_mail TINYINT(1) NOT NULL DEFAULT 1, updated_at DATETIME(6) NOT NULL, '
                . 'INDEX ix_member_options_birthday (birth_md), ' . $user('fk_member_options_user') . ')'
                . self::OPTIONS,
            'CREATE TABLE membership_terms_acceptances (user_id BIGINT NOT NULL, terms_hash CHAR(64)' . self::ASCII
                . ' NOT NULL, source VARCHAR(16)' . self::ASCII . ' NOT NULL, accepted_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (user_id, terms_hash), ' . $user('fk_terms_acceptances_user') . ')' . self::OPTIONS,
            'CREATE TABLE registration_questions (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, locale VARCHAR(16)'
                . self::ASCII . ' NOT NULL, question VARCHAR(255) NOT NULL, answers VARCHAR(1000) NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, INDEX ix_registration_questions_locale (locale, id))'
                . self::OPTIONS,
            'CREATE TABLE usergroup_settings (usergroup_id BIGINT NOT NULL PRIMARY KEY, '
                . 'joinable TINYINT(1) NOT NULL DEFAULT 0, description VARCHAR(300) NOT NULL, '
                . 'updated_at DATETIME(6) NOT NULL, ' . $group('fk_usergroup_settings_group') . ')' . self::OPTIONS,
            'CREATE TABLE usergroup_leaders (usergroup_id BIGINT NOT NULL, user_id BIGINT NOT NULL, '
                . 'created_at DATETIME(6) NOT NULL, PRIMARY KEY (usergroup_id, user_id), '
                . 'INDEX ix_usergroup_leaders_user (user_id), ' . $group('fk_usergroup_leaders_group') . ', '
                . $user('fk_usergroup_leaders_user') . ')' . self::OPTIONS,
            'CREATE TABLE group_applications (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'usergroup_id BIGINT NOT NULL, user_id BIGINT NOT NULL, message VARCHAR(500) NOT NULL, '
                . 'status VARCHAR(10)' . self::ASCII . ' NOT NULL, decided_by BIGINT NULL, '
                . 'decided_at DATETIME(6) NULL, created_at DATETIME(6) NOT NULL, '
                . 'INDEX ix_group_applications_queue (usergroup_id, status, id), '
                . 'INDEX ix_group_applications_user (user_id, id), ' . $group('fk_group_applications_group') . ', '
                . $user('fk_group_applications_user') . ', CONSTRAINT fk_group_applications_decider FOREIGN KEY '
                . '(decided_by) REFERENCES users (id) ON DELETE SET NULL)' . self::OPTIONS,
            'CREATE TABLE group_mail_jobs (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'usergroup_id BIGINT NOT NULL, author_id BIGINT NULL, subject VARCHAR(150) NOT NULL, '
                . 'body TEXT NOT NULL, cursor_user_id BIGINT NOT NULL DEFAULT 0, sent INT NOT NULL DEFAULT 0, '
                . 'status VARCHAR(10)' . self::ASCII . ' NOT NULL, created_at DATETIME(6) NOT NULL, '
                . 'finished_at DATETIME(6) NULL, INDEX ix_group_mail_jobs_created (created_at), '
                . $group('fk_group_mail_jobs_group') . ', CONSTRAINT fk_group_mail_jobs_author FOREIGN KEY '
                . '(author_id) REFERENCES users (id) ON DELETE SET NULL)' . self::OPTIONS,
            'CREATE TABLE timed_memberships (user_id BIGINT NOT NULL, usergroup_id BIGINT NOT NULL, '
                . 'expires_at DATETIME(6) NOT NULL, granted_by BIGINT NULL, granted_at DATETIME(6) NOT NULL, '
                . 'PRIMARY KEY (user_id, usergroup_id), INDEX ix_timed_memberships_expiry (expires_at), '
                . $user('fk_timed_memberships_user') . ', ' . $group('fk_timed_memberships_group') . ', '
                . 'CONSTRAINT fk_timed_memberships_granter FOREIGN KEY (granted_by) REFERENCES users (id) '
                . 'ON DELETE SET NULL)' . self::OPTIONS,
            'CREATE TABLE group_promotion_rules (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'usergroup_id BIGINT NOT NULL, min_posts INT NOT NULL, min_days INT NOT NULL, '
                . 'min_reputation INT NOT NULL, created_at DATETIME(6) NOT NULL, '
                . $group('fk_group_promotion_rules_group') . ')' . self::OPTIONS,
            'CREATE TABLE membership_task_state (name VARCHAR(32)' . self::ASCII . ' NOT NULL PRIMARY KEY, '
                . 'cursor_id BIGINT NOT NULL, updated_at DATETIME(6) NOT NULL)' . self::OPTIONS,
            'ALTER TABLE profile_fields ADD COLUMN required TINYINT(1) NOT NULL DEFAULT 0',
            'CREATE TABLE profile_field_options (id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
                . 'field_id BIGINT NOT NULL, label VARCHAR(100) NOT NULL, position INT NOT NULL, '
                . 'INDEX ix_profile_field_options_field (field_id, position), CONSTRAINT fk_profile_field_options '
                . 'FOREIGN KEY (field_id) REFERENCES profile_fields (id) ON DELETE CASCADE)' . self::OPTIONS,
        ];
    }
}
