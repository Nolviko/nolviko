<?php

declare(strict_types=1);

namespace Nolviko\Core\Application;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Container\ContainerBuilder;
use Nolviko\Core\Database\ExtensionEvents;
use Nolviko\Core\Database\OutboxQueue;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Core\Events\CooperativeTaskRunner;
use Nolviko\Core\Events\OutboxLease;
use Nolviko\Core\Extensions\ExtensionBootstrap;
use Nolviko\Core\Extensions\ExtensionRegistry;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Mail\MailComposer;
use Nolviko\Core\Mail\StoredMailSettings;
use Nolviko\Core\Mail\SymfonyMailer;
use Nolviko\Core\Mail\TransportFactory;
use Nolviko\Core\Routing\RouterFactory;
use Nolviko\Core\Updates\SiteAccessLock;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Api\Infrastructure\Webhooks;
use Nolviko\Modules\Community\Infrastructure\CommunityAccess;
use Nolviko\Modules\Community\Infrastructure\CommunityInteractions;
use Nolviko\Modules\Community\Infrastructure\CommunityMail;
use Nolviko\Modules\Community\Infrastructure\CommunityMailTemplate;
use Nolviko\Modules\Community\Infrastructure\CommunityNotifications;
use Nolviko\Modules\Community\Infrastructure\ImageProcessor;
use Nolviko\Modules\Community\Infrastructure\MemberProfiles;
use Nolviko\Modules\Community\Infrastructure\PrivateMedia;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Gallery\Infrastructure\Gallery;
use Nolviko\Modules\Gallery\Infrastructure\GalleryImages;
use Nolviko\Modules\Membership\Infrastructure\GroupMembership;
use Nolviko\Modules\Importers\Infrastructure\ImportFileStore;
use Nolviko\Modules\Importers\Infrastructure\PrivateImportFiles;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Seo\Infrastructure\IndexNow;
use Nolviko\Modules\Seo\Infrastructure\Sitemaps;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Infrastructure\AccountPrivacy;
use Nolviko\Modules\Users\Infrastructure\PersonalDataExport;
use Nolviko\Modules\Users\Infrastructure\ResetMailQueue;
use Nolviko\Modules\Users\Infrastructure\TemplatedAccountMail;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

/** One handler composition for visitor, administrator and opt-in CLI execution; no handler from user input. */
final class TaskRuntime
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly string $root,
        private readonly string $storage,
        private readonly ?ExtensionRegistry $extensions = null,
    ) {
    }

    public function run(int $limit = 1, int $milliseconds = 5000): array
    {
        $lease = (new SiteAccessLock($this->storage))->acquire(false);
        if ($lease === false) {
            throw new RuntimeException('tasks_unavailable');
        }
        try {
            return $this->runUnlocked($limit, $milliseconds);
        } finally {
            SiteAccessLock::release($lease);
        }
    }

    private function runUnlocked(int $limit, int $milliseconds): array
    {
        $root = $this->root;
        $storage = $this->storage;
        $public = $root . '/public';
        $config = $this->config;
        $db = $this->db;

        if (
            !in_array($config->databaseDriver, ['mysql', 'mariadb', 'postgresql'], true)
            || file_exists($root . '/config/maintenance.lock')
                || is_link($root . '/config/maintenance.lock')
            || (int) $db->fetchOne("SELECT COUNT(*) FROM migration_history WHERE id = '0040_community' "
                . "AND status = 'applied'") !== 1
        ) {
            throw new RuntimeException('tasks_unavailable');
        }

        $privacy = new AccountPrivacy(
            $db,
            new PasswordHasher(),
            new RateBuckets($db),
            new PersonalDataExport($db, $storage, $public)
        );
        $handler = static function (OutboxLease $lease, Closure $guard) use ($privacy): bool {
            $payload = json_decode($lease->payload, true, 4, JSON_THROW_ON_ERROR);
            $requested = is_array($payload) ? ($payload['requested_at'] ?? null) : null;

            if (
                $lease->aggregateId < 1 || !is_string($requested)
                || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/D', $requested) !== 1
            ) {
                throw new RuntimeException('outbox_payload_invalid');
            }

            return $privacy->finalizeScheduled($lease->aggregateId, $requested, $guard);
        };
        $exports = (new PersonalDataExport($db, $storage, $public))->resumable();
        $exportHandler = static function (OutboxLease $lease, Closure $guard) use ($exports): bool {
            $payload = json_decode($lease->payload, true, 4, JSON_THROW_ON_ERROR);
            $job = is_array($payload) ? ($payload['job'] ?? null) : null;

            if (!is_string($job) || preg_match('/^[a-f0-9]{32}$/D', $job) !== 1) {
                throw new RuntimeException('outbox_payload_invalid');
            }

            return $exports->step($lease->aggregateId, $job, $guard);
        };
        $allowed = static fn(): bool => !file_exists($root . '/config/maintenance.lock')
            && !is_link($root . '/config/maintenance.lock');
        $registry = $this->extensions ?? new ExtensionRegistry();

        if ($this->extensions === null) {
            ExtensionBootstrap::register(
                ExtensionBootstrap::manager($root, $storage),
                new ContainerBuilder(),
                $registry,
            );
        }

        $extensionHandlers = (new ExtensionEvents($db, $registry))->handlers();
        $visibility = new ForumVisibility(
            (new CacheFactory($storage))->namespace('permissions'),
            static fn(): int => (new VersionCounter($db))->current(VersionCounter::PERMISSION_RULES)
        );
        $access = new CommunityAccess($db, $visibility);
        $profiles = new MemberProfiles($db, $access);
        $interactions = new CommunityInteractions($db, $access, $profiles);
        $notifications = new CommunityNotifications($db, $access, $interactions);
        // Same precedence as the request mailer (ADR-034 amendment 2026-11-21): panel SMTP unless local.php sets it.
        try {
            $stored = array_map('strval', $db->fetchAllKeyValue(
                "SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'mail.%'",
            ));
        } catch (Throwable) {
            $stored = [];
        }
        $mailSettings = StoredMailSettings::resolve($config, $stored);
        $mailer = new SymfonyMailer(TransportFactory::create($mailSettings, 2), $mailSettings, new NullLogger());
        $router = RouterFactory::create($root, $storage);
        $mail = new CommunityMail(
            $db,
            $mailer,
            $config->appUrl ?? '',
            $router,
            new CommunityMailTemplate($root, $storage)
        );
        $media = new PrivateMedia($db, $access, $profiles, new ImageProcessor(), $storage, $public);
        $optionalSchema = $db->fetchFirstColumn("SELECT id FROM migration_history WHERE id IN "
            . "('0042_import_jobs', '0043_seo_sitemaps', '0047_media_gallery', '0049_webhooks', '0070_membership') "
            . "AND status = 'applied'");
        $sitemaps = null;
        if (in_array('0043_seo_sitemaps', $optionalSchema, true)) {
            $catalog = PermissionCatalog::core(true);
            $permissions = new PermissionResolver(
                $catalog,
                new DbalPermissionRuleStore($db, $catalog),
                new DbalForumTree($db)
            );
            $sitemaps = new Sitemaps(
                $db,
                $visibility,
                $permissions->guest(...),
                new SiteUrl($config->appUrl),
                RouterFactory::create($root, $storage)
            );
        }

        $handlers = ['user.deletion_requested' => $handler, 'user.export_requested' => $exportHandler,
            'thread.created' => $notifications->fanout(...), 'post.created' => $notifications->fanout(...),
            'post.approved' => $notifications->fanout(...), 'thread.approved'
                => $notifications->fanout(...),
            'community.mail' => $mail->deliver(...)];
        $handlers[ResetMailQueue::EVENT] = static function (OutboxLease $lease) use (
            $db,
            $config,
            $root,
            $storage,
            $mailer,
            $router
        ): bool {
            $payload = json_decode($lease->payload, true, 4, JSON_THROW_ON_ERROR);
            $translator = TranslatorFactory::create($root . '/translations', $payload['locale']);
            $composer = new MailComposer(TwigFactory::create($root, $storage, $translator), $translator);
            return (new ResetMailQueue($db, $config->appSecret))->deliver(
                $lease,
                new TemplatedAccountMail($mailer, $composer, $router, $config->appUrl)
            );
        };
        if ($sitemaps !== null) {
            $handlers['seo.sitemap_requested'] = $sitemaps->build(...);
        }

        // IndexNow (2026-11-08): new public threads, when the administrator turned it on.
        if ($config->appUrl !== null && $config->appUrl !== '') {
            $catalog = PermissionCatalog::core(true);
            $resolver = new PermissionResolver(
                $catalog,
                new DbalPermissionRuleStore($db, $catalog),
                new DbalForumTree($db),
            );
            $indexNow = new IndexNow(
                $db,
                static fn() => $visibility->readable($resolver->guest()),
                $config->appUrl,
                IndexNow::key($config->appSecret->reveal()),
            );
            $created = $handlers['thread.created'];
            $handlers['thread.created'] = static function (
                OutboxLease $lease,
                Closure $guard
            ) use (
                $created,
                $indexNow,
            ): bool {
                $done = $created($lease, $guard);

                if ($done) {
                    $indexNow->submit($lease);
                }

                return $done;
            };
        }

        // Webhooks (2026-11-08): after the member notices of a new thread or reply are complete, once per event.
        if (in_array('0049_webhooks', $optionalSchema, true)) {
            $catalog = PermissionCatalog::core(true);
            $guest = (new PermissionResolver(
                $catalog,
                new DbalPermissionRuleStore($db, $catalog),
                new DbalForumTree($db),
            ))->guest(...);
            $webhooks = new Webhooks($db, static fn() => $visibility->readable($guest()), $config->appUrl ?? '');

            foreach (Webhooks::EVENTS as $type) {
                $inner = $handlers[$type];
                $handlers[$type] = static function (OutboxLease $lease, Closure $guard) use ($inner, $webhooks): bool {
                    $done = $inner($lease, $guard);

                    if ($done) {
                        $webhooks->deliver($lease);
                    }

                    return $done;
                };
            }
        }
        foreach ($extensionHandlers as $type => $extensionHandler) {
            $coreHandler = $handlers[$type] ?? null;
            $handlers[$type] = static function (
                OutboxLease $lease,
                Closure $guard
            ) use (
                $coreHandler,
                $extensionHandler,
            ): bool {
                if ($coreHandler !== null && $coreHandler($lease, $guard) === false) {
                    return false;
                }

                return $extensionHandler($lease, $guard);
            };
        }

        // Member notices (and the webhooks and IndexNow pings riding on them) can pile up after busy hours or an
        // import; the sitemap, password-reset mail, account deletion and exports must not wait behind them.
        $result = (new CooperativeTaskRunner(
            $db,
            new OutboxQueue($db),
            $handlers,
            $allowed,
            ['thread.created', 'post.created', 'post.approved', 'thread.approved'],
        ))->run($limit, $milliseconds);
        $exports->garbageCollect();
        (new OutboxQueue($db))->prune();
        $sitemaps?->collect();
        $media->collect();
        if (in_array('0042_import_jobs', $optionalSchema, true)) {
            (new PrivateImportFiles($db, new ImportFileStore($storage, $public)))->collect();
        }

        // Gallery files left by deleted accounts (2026-11-08): one rotating key bucket per run.
        if (in_array('0047_media_gallery', $optionalSchema, true)) {
            (new Gallery($db, new GalleryImages(), $storage, $public))->collect(random_int(0, 255));
        }

        // beta.18 (G3): timed memberships end, promotion rules and group mail advance one small batch per run.
        if (in_array('0070_membership', $optionalSchema, true)) {
            try {
                $groups = new GroupMembership($db);
                $groups->expireDue();
                $groups->promoteBatch();
                $groups->sendMailBatch($mailer);
            } catch (Throwable) {
                // A failed step is retried on the next run.
            }
        }

        return $result;
    }
}
