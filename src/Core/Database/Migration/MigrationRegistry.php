<?php

declare(strict_types=1);

namespace Nolviko\Core\Database\Migration;

use InvalidArgumentException;
use Nolviko\Core\Database\Migration\Core\BackupReference;
use Nolviko\Core\Database\Migration\Core\CoreFoundation;
use Nolviko\Core\Database\Migration\Core\ExtensionContracts;
use Nolviko\Core\Database\Migration\Core\InstalledRelease;
use Nolviko\Core\Database\Migration\Core\OutboxLeases;
use Nolviko\Core\Database\Migration\Core\RateLimitBuckets;
use Nolviko\Core\Database\Migration\Core\UpdateRuns;
use Nolviko\Core\Events\OutboxTable;
use Nolviko\Modules\Admin\Infrastructure\AdminSavedFiltersTable;
use Nolviko\Modules\Admin\Infrastructure\ForumModeratorsTable;
use Nolviko\Modules\Api\Infrastructure\WebhookTables;
use Nolviko\Modules\Community\Infrastructure\CommunityTables;
use Nolviko\Modules\Community\Infrastructure\MemberStandingTables;
use Nolviko\Modules\Conversations\Infrastructure\ConversationReadsColumn;
use Nolviko\Modules\Conversations\Infrastructure\ConversationsTable;
use Nolviko\Modules\Forums\Infrastructure\ForumExtrasColumns;
use Nolviko\Modules\Forums\Infrastructure\ForumQuestionMode;
use Nolviko\Modules\Forums\Infrastructure\ForumsTable;
use Nolviko\Modules\Forums\Infrastructure\ForumStatisticsTable;
use Nolviko\Modules\Forums\Infrastructure\ForumSubscriptionsTable;
use Nolviko\Modules\Gallery\Infrastructure\GalleryTables;
use Nolviko\Modules\Groups\Infrastructure\UsergroupsTable;
use Nolviko\Modules\Importers\Infrastructure\ImportPanelTables;
use Nolviko\Modules\Importers\Infrastructure\ImportTables;
use Nolviko\Modules\Moderation\Infrastructure\BansTable;
use Nolviko\Modules\Moderation\Infrastructure\ModerationActionsTable;
use Nolviko\Modules\Moderation\Infrastructure\QueueIndexes;
use Nolviko\Modules\Moderation\Infrastructure\ReportsTable;
use Nolviko\Modules\Moderation\Infrastructure\SanctionsTable;
use Nolviko\Modules\Permissions\Infrastructure\PermissionRulesTable;
use Nolviko\Modules\Permissions\Infrastructure\PermissionsTable;
use Nolviko\Modules\Posts\Infrastructure\PostsRenderedHtmlColumn;
use Nolviko\Modules\Posts\Infrastructure\PostsTable;
use Nolviko\Modules\Posts\Infrastructure\RepliesTable;
use Nolviko\Modules\Search\Infrastructure\SearchIndex;
use Nolviko\Modules\Seo\Infrastructure\SeoTables;
use Nolviko\Modules\Tasks\Infrastructure\TaskIndexes;
use Nolviko\Modules\Threads\Infrastructure\DraftsTable;
use Nolviko\Modules\Threads\Infrastructure\EditWindowUnlimited;
use Nolviko\Modules\Threads\Infrastructure\GuestPostColumns;
use Nolviko\Modules\Threads\Infrastructure\ModerationTable;
use Nolviko\Modules\Threads\Infrastructure\ReadTrackingTable;
use Nolviko\Modules\Threads\Infrastructure\ThreadRedirectsTable;
use Nolviko\Modules\Threads\Infrastructure\ThreadsTable;
use Nolviko\Modules\Threads\Infrastructure\ThreadTaxonomyTables;
use Nolviko\Modules\Threads\Infrastructure\ThreadViewCounts;
use Nolviko\Modules\Users\Infrastructure\AccountDeletionProgress;
use Nolviko\Modules\Users\Infrastructure\AccountPrivacyTable;
use Nolviko\Modules\Users\Infrastructure\EmailChangeTable;
use Nolviko\Modules\Users\Infrastructure\EmailVerificationTable;
use Nolviko\Modules\Users\Infrastructure\ExternalIdentityTable;
use Nolviko\Modules\Users\Infrastructure\LoginAttemptsTable;
use Nolviko\Modules\Users\Infrastructure\PasswordResetTable;
use Nolviko\Modules\Users\Infrastructure\RememberTokensTable;
use Nolviko\Modules\Users\Infrastructure\SecondFactorTables;
use Nolviko\Modules\Users\Infrastructure\UsersAppearanceColumn;
use Nolviko\Modules\Users\Infrastructure\UsersTable;
use Nolviko\Modules\Users\Infrastructure\UsersTimeZoneColumn;

/**
 * The explicit, ordered list of migrations (no directory scanning). The first one must be CoreFoundation,
 * which creates the runner's own tables.
 */
final class MigrationRegistry
{
    private const ID_PATTERN = '/^[0-9]{4}_[a-z0-9]+(_[a-z0-9]+)*$/D';

    /** @var list<Migration> */
    public readonly array $migrations;

    /**
     * @param list<Migration> $migrations In application order; IDs must be valid, unique and ascending.
     *
     * @throws InvalidArgumentException `migration_registry_invalid:<id>` for a bad, duplicate or out-of-order ID.
     */
    public function __construct(array $migrations)
    {
        $previous = '';

        foreach ($migrations as $index => $migration) {
            $id = $migration->id();
            $first = $index === 0;

            if (
                preg_match(self::ID_PATTERN, $id) !== 1
                || strcmp($id, $previous) <= 0
                || $first !== ($migration instanceof CoreFoundation)
            ) {
                throw new InvalidArgumentException('migration_registry_invalid:' . $id);
            }

            $previous = $id;
        }

        $this->migrations = $migrations;
    }

    /**
     * The core application's migrations, core modules included. Extensions get their own registry and
     * history (M06).
     */
    public static function core(): self
    {
        return new self([
            new CoreFoundation(),
            new UsersTable(),
            new RememberTokensTable(),
            new LoginAttemptsTable(),
            new UsergroupsTable(),
            new PermissionsTable(),
            new UsersTimeZoneColumn(),
            new PermissionRulesTable(),
            new ForumsTable(),
            new ThreadsTable(),
            new PostsTable(),
            new PostsRenderedHtmlColumn(),
            new OutboxTable(),
            new ForumStatisticsTable(),
            new RepliesTable(),
            new ModerationTable(),
            new ThreadRedirectsTable(),
            new RateLimitBuckets(),
            new ReportsTable(),
            new QueueIndexes(),
            new SanctionsTable(),
            new BansTable(),
            new EmailVerificationTable(),
            new PasswordResetTable(),
            new EmailChangeTable(),
            new ReadTrackingTable(),
            new DraftsTable(),
            new UpdateRuns(),
            new BackupReference(),
            new InstalledRelease(),
            new AccountPrivacyTable(),
            new OutboxLeases(),
            new SearchIndex(),
            new ConversationsTable(),
            new AccountDeletionProgress(),
            new AdminSavedFiltersTable(),
            new ForumModeratorsTable(),
            new UsersAppearanceColumn(),
            new ExtensionContracts(),
            new CommunityTables(),
            new TaskIndexes(),
            new ImportTables(),
            new SeoTables(),
            new ConversationReadsColumn(),
            new ThreadViewCounts(),
            new ThreadTaxonomyTables(),
            new GalleryTables(),
            new MemberStandingTables(),
            new WebhookTables(),
            new EditWindowUnlimited(),
            new ForumQuestionMode(),
            new SecondFactorTables(),
            new ExternalIdentityTable(),
            new ImportPanelTables(),
            // beta.18 G4 (forum features): 0075-0078.
            new ForumExtrasColumns(),
            new ForumSubscriptionsTable(),
            new GuestPostColumns(),
            new ModerationActionsTable(),
        ]);
    }

    public function find(string $id): ?Migration
    {
        foreach ($this->migrations as $migration) {
            if ($migration->id() === $id) {
                return $migration;
            }
        }

        return null;
    }

    /**
     * SHA-256 over ID, phase, backend and statements, so any edit of an applied migration is detected.
     *
     * @throws MigrationException When the migration has no statements for the backend.
     */
    public static function checksum(Migration $migration, string $backend): string
    {
        return hash('sha256', implode("\0", [
            $migration->id(),
            $migration->phase()->value,
            $backend,
            ...self::statements($migration, $backend),
        ]));
    }

    /**
     * The statements of a migration for a backend: MariaDB runs the MySQL statements through MariaDbDialect;
     * PostgreSQL uses a migration's own PostgreSQL statements when it has them, otherwise PostgresDialect's
     * translation of the MySQL ones (2026-11-14). The PostgreSQL foundation also creates `utc_timestamp(int)`,
     * so runtime SQL using MySQL's UTC clock function works unchanged.
     *
     * @return list<string>
     */
    public static function statements(Migration $migration, string $backend): array
    {
        if ($backend === MariaDbDialect::BACKEND) {
            return MariaDbDialect::translate($migration->statements('mysql'));
        }

        if ($backend !== PostgresDialect::BACKEND) {
            return $migration->statements($backend);
        }

        try {
            $statements = $migration->statements($backend);
        } catch (MigrationException $unsupported) {
            if (!str_starts_with($unsupported->getMessage(), 'migration_backend_unsupported')) {
                throw $unsupported;
            }

            $statements = PostgresDialect::translate($migration->statements('mysql'));
        }

        return $migration instanceof CoreFoundation ? [...$statements, PostgresDialect::CLOCK_FUNCTION] : $statements;
    }
}
