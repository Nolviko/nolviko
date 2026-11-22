<?php

declare(strict_types=1);

namespace Nolviko\Modules\Forums\Infrastructure;

use Doctrine\DBAL\Connection;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Modules\Forums\Domain\ForumRuleViolation;
use Nolviko\Modules\Permissions\Domain\ForumLocks;

/**
 * Forum options of the beta.18 roadmap (G4): link sections and their click counter (06), whether posts count for
 * the member's post count (32) and forum passwords (43, ADR-041). A password is
 * stored only as a password_hash(); setting or removing it bumps the permission rules version, so every cached
 * forum tree and visibility set is rebuilt and earlier unlocks end.
 */
final class ForumExtras
{
    public const MIN_PASSWORD = 4;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed> $input Form fields: link_url, link_counts, counts_posts, password_action
     *     (`keep`, `set`, `clear`) and password.
     *
     * @throws ForumRuleViolation `forum_not_found`, `forum_link_invalid` or `forum_password_invalid`.
     */
    public function save(int $forumId, array $input): void
    {
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $type = $this->db->fetchOne('SELECT type FROM forums WHERE id = ?', [$forumId]);

        if ($type === false) {
            throw new ForumRuleViolation('forum_not_found');
        }

        // Guest posting (35) is deferred to after RC1 (ADR-040): the column exists but no form sets it.
        $values = ['counts_posts' => $text('counts_posts') === '1' ? 1 : 0];

        if ($type === 'link') {
            $url = $text('link_url');

            if (
                strlen($url) > 2000 || filter_var($url, FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            ) {
                throw new ForumRuleViolation('forum_link_invalid');
            }

            $values += ['link_url' => $url, 'link_counts' => $text('link_counts') === '1' ? 1 : 0];
        }

        $action = $text('password_action');
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';

        if ($action === 'set' && (mb_strlen($password) < self::MIN_PASSWORD || strlen($password) > 200)) {
            throw new ForumRuleViolation('forum_password_invalid');
        }

        if ($action === 'set') {
            $values['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        } elseif ($action === 'clear') {
            $values['password_hash'] = null;
        }

        $this->db->transactional(static function (Connection $db) use ($forumId, $values): void {
            $db->update('forums', $values, ['id' => $forumId]);

            if (array_key_exists('password_hash', $values)) {
                VersionCounter::bump($db, VersionCounter::PERMISSION_RULES);
            }
        });
    }

    /**
     * Checks a forum password (ADR-041).
     *
     * @return string|null The fingerprint to keep in the session (ForumLocks::SESSION_KEY), or null when wrong.
     */
    public function unlock(int $forumId, string $password): ?string
    {
        $hash = $this->db->fetchOne('SELECT password_hash FROM forums WHERE id = ?', [$forumId]);

        if (!is_string($hash) || $hash === '' || strlen($password) > 200 || !password_verify($password, $hash)) {
            return null;
        }

        return ForumLocks::fingerprint($hash);
    }

    /** Counts one click on a link section when its counter is on (roadmap 06). */
    public function click(int $forumId): void
    {
        $this->db->executeStatement(
            "UPDATE forums SET link_clicks = link_clicks + 1 WHERE id = ? AND type = 'link' AND link_counts = 1",
            [$forumId],
        );
    }
}
