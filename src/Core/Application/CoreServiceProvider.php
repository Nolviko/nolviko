<?php

declare(strict_types=1);

namespace Nolviko\Core\Application;

use Closure;
use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Cache\RedisSettingsStore;
use Nolviko\Core\Config\Config;
use Nolviko\Core\Config\MailSettings;
use Nolviko\Core\Config\PostingRules;
use Nolviko\Core\Config\RegistrationRules;
use Nolviko\Core\Config\Secret;
use Nolviko\Core\Container\ContainerBuilder;
use Nolviko\Core\Container\Lazy;
use Nolviko\Core\Container\ServiceProvider;
use Nolviko\Core\Database\ConnectionFactory;
use Nolviko\Core\Database\ExtensionMigrations;
use Nolviko\Core\Database\Migration\MigrationRegistry;
use Nolviko\Core\Database\Migration\SchemaInstaller;
use Nolviko\Core\Database\RateBuckets;
use Nolviko\Core\Database\UpdatePreparation;
use Nolviko\Core\Database\VersionCounter;
use Nolviko\Core\Events\Outbox;
use Nolviko\Core\Extensions\BeforeHooks;
use Nolviko\Core\Extensions\ExtensionHandlers;
use Nolviko\Core\Extensions\ExtensionManager;
use Nolviko\Core\Extensions\ExtensionRegistry;
use Nolviko\Core\Extensions\ExtensionRouteGuard;
use Nolviko\Core\Extensions\IdentityProviders;
use Nolviko\Core\Extensions\SecondFactorProviders;
use Nolviko\Core\Http\AppearanceHandler;
use Nolviko\Core\Http\CspNonce;
use Nolviko\Core\Http\CsrfGuard;
use Nolviko\Core\Http\ErrorReporter;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\HttpsGuard;
use Nolviko\Core\Http\InstallationGuard;
use Nolviko\Core\Http\InstallerDatabaseHandler;
use Nolviko\Core\Http\InstallerHandler;
use Nolviko\Core\Http\InstallerMailHandler;
use Nolviko\Core\Http\InstallerMigrateHandler;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\Kernel;
use Nolviko\Core\Http\MaintenanceGuard;
use Nolviko\Core\Http\ResponseCookies;
use Nolviko\Core\Http\SeoMetadata;
use Nolviko\Core\Http\ThemeRequest;
use Nolviko\Core\Installer\DatabaseSetup;
use Nolviko\Core\Installer\FreshInstallDefaults;
use Nolviko\Core\Installer\MailSetup;
use Nolviko\Core\Installer\PostInstallExposure;
use Nolviko\Core\Installer\PrerequisiteCheck;
use Nolviko\Core\Installer\SetupCode;
use Nolviko\Core\Installer\WebExposureProbe;
use Nolviko\Core\Locale\LocaleContext;
use Nolviko\Core\Locale\LocaleFormatter;
use Nolviko\Core\Locale\LocaleResolver;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Locale\RequestTranslator;
use Nolviko\Core\Locale\TranslatorFactory;
use Nolviko\Core\Mail\MailComposer;
use Nolviko\Core\Mail\Mailer;
use Nolviko\Core\Mail\SymfonyMailer;
use Nolviko\Core\Mail\TransportFactory;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\ReleaseId;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Routing\RouterFactory;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\NativeSession;
use Nolviko\Core\Session\Session;
use Nolviko\Core\Settings\EffectivePostingRules;
use Nolviko\Core\Settings\SiteSettings;
use Nolviko\Core\Updates\GitHubReleaseDiscovery;
use Nolviko\Core\Updates\NativeReleaseHttpClient;
use Nolviko\Core\Updates\PackageAssetDownloader;
use Nolviko\Core\Updates\PackageUpdates;
use Nolviko\Core\Updates\RecoveryPreparation;
use Nolviko\Core\Updates\SiteAccessLock;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Core\View\ThemeArchive;
use Nolviko\Core\View\ThemeBuildStore;
use Nolviko\Core\View\ThemePresentation;
use Nolviko\Core\View\ThemeSlots;
use Nolviko\Core\View\ThemeTokens;
use Nolviko\Core\View\TwigFactory;
use Nolviko\Modules\Admin\Http\AdminGuard;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Http\AuditLogHandler;
use Nolviko\Modules\Admin\Http\BanListHandler;
use Nolviko\Modules\Admin\Http\DashboardHandler;
use Nolviko\Modules\Admin\Http\DiagnosticsHandler;
use Nolviko\Modules\Admin\Http\ExtensionsHandler;
use Nolviko\Modules\Admin\Http\ForumManagementHandler;
use Nolviko\Modules\Admin\Http\ForumModeratorsHandler;
use Nolviko\Modules\Admin\Http\GroupHandler;
use Nolviko\Modules\Admin\Http\GroupsHandler;
use Nolviko\Modules\Admin\Http\MailSettingsHandler;
use Nolviko\Modules\Admin\Http\MemberExtrasAdminHandler;
use Nolviko\Modules\Admin\Http\MemberHandler;
use Nolviko\Modules\Admin\Http\MembersHandler;
use Nolviko\Modules\Admin\Http\PermissionSimulatorHandler;
use Nolviko\Modules\Admin\Http\ReauthHandler;
use Nolviko\Modules\Admin\Http\RegistrationSettingsHandler;
use Nolviko\Modules\Admin\Http\SavedFiltersHandler;
use Nolviko\Modules\Admin\Http\SettingsCatalog;
use Nolviko\Modules\Admin\Http\SettingsRegistryHandler;
use Nolviko\Modules\Admin\Http\SiteSettingsHandler;
use Nolviko\Modules\Admin\Http\TaxonomyAdminHandler;
use Nolviko\Modules\Admin\Http\ThemesHandler;
use Nolviko\Modules\Admin\Http\UpdatesHandler;
use Nolviko\Modules\Admin\Http\WebhooksAdminHandler;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminStats;
use Nolviko\Modules\Admin\Infrastructure\DbalAuditLog;
use Nolviko\Modules\Admin\Infrastructure\DbalMemberList;
use Nolviko\Modules\Admin\Infrastructure\DbalUpdateOverview;
use Nolviko\Modules\Admin\Infrastructure\ForumAdmin;
use Nolviko\Modules\Admin\Infrastructure\ForumGuestVisibility;
use Nolviko\Modules\Admin\Infrastructure\ForumModeratorAssignments;
use Nolviko\Modules\Admin\Infrastructure\GroupAdministration;
use Nolviko\Modules\Admin\Infrastructure\MemberIdentity;
use Nolviko\Modules\Admin\Infrastructure\SavedAdminFilters;
use Nolviko\Modules\Admin\Infrastructure\SystemDiagnostics;
use Nolviko\Modules\Api\Http\ApiHandler;
use Nolviko\Modules\Api\Infrastructure\Webhooks;
use Nolviko\Modules\Community\Http\CommunityHandler;
use Nolviko\Modules\Community\Http\MemberLinkHandler;
use Nolviko\Modules\Community\Http\MemberSuggestHandler;
use Nolviko\Modules\Community\Infrastructure\CommunityAccess;
use Nolviko\Modules\Community\Infrastructure\CommunityActions;
use Nolviko\Modules\Community\Infrastructure\CommunityActivity;
use Nolviko\Modules\Community\Infrastructure\CommunityInteractions;
use Nolviko\Modules\Community\Infrastructure\CommunityNotifications;
use Nolviko\Modules\Community\Infrastructure\CommunityPolls;
use Nolviko\Modules\Community\Infrastructure\CommunityRead;
use Nolviko\Modules\Community\Infrastructure\ImageProcessor;
use Nolviko\Modules\Community\Infrastructure\MemberProfiles;
use Nolviko\Modules\Community\Infrastructure\MemberStanding;
use Nolviko\Modules\Community\Infrastructure\PrivateMedia;
use Nolviko\Modules\Conversations\Http\ConversationsHandler;
use Nolviko\Modules\Conversations\Infrastructure\PrivateConversations;
use Nolviko\Modules\Forums\Domain\ForumIndex;
use Nolviko\Modules\Forums\Domain\ForumRepository;
use Nolviko\Modules\Forums\Http\DiscussionFeed;
use Nolviko\Modules\Forums\Http\ForumIndexHandler;
use Nolviko\Modules\Forums\Http\TagPageHandler;
use Nolviko\Modules\Forums\Infrastructure\DbalForumIndex;
use Nolviko\Modules\Forums\Infrastructure\DbalForumRepository;
use Nolviko\Modules\Forums\Infrastructure\DbalForumTree;
use Nolviko\Modules\Gallery\Http\GalleryHandler;
use Nolviko\Modules\Gallery\Infrastructure\Gallery;
use Nolviko\Modules\Gallery\Infrastructure\GalleryImages;
use Nolviko\Modules\Groups\Domain\UsergroupRepository;
use Nolviko\Modules\Groups\Infrastructure\DbalUsergroupRepository;
use Nolviko\Modules\Importers\Http\ImportsHandler;
use Nolviko\Modules\Importers\Http\LegacyHandler;
use Nolviko\Modules\Importers\Http\PrivateFileHandler;
use Nolviko\Modules\Importers\Infrastructure\ImportAdministration;
use Nolviko\Modules\Importers\Infrastructure\ImportFileStore;
use Nolviko\Modules\Importers\Infrastructure\ImportSources;
use Nolviko\Modules\Importers\Infrastructure\InlineAttachments;
use Nolviko\Modules\Importers\Infrastructure\LegacyRedirects;
use Nolviko\Modules\Importers\Infrastructure\PrivateImportFiles;
use Nolviko\Modules\Moderation\Domain\BulkModeration;
use Nolviko\Modules\Moderation\Domain\ManageBans;
use Nolviko\Modules\Moderation\Domain\ManageSanctions;
use Nolviko\Modules\Moderation\Domain\ReportPost;
use Nolviko\Modules\Moderation\Domain\ResolveReport;
use Nolviko\Modules\Moderation\Http\BulkModerationHandler;
use Nolviko\Modules\Moderation\Http\ModerationQueueHandler;
use Nolviko\Modules\Moderation\Http\ReportDecisionHandler;
use Nolviko\Modules\Moderation\Http\ReportHandler;
use Nolviko\Modules\Moderation\Http\UserSanctionsHandler;
use Nolviko\Modules\Moderation\Infrastructure\DbalAccountApprovals;
use Nolviko\Modules\Moderation\Infrastructure\DbalBanList;
use Nolviko\Modules\Moderation\Infrastructure\DbalModerationQueue;
use Nolviko\Modules\Moderation\Infrastructure\DbalReportDecisions;
use Nolviko\Modules\Moderation\Infrastructure\DbalReportWriter;
use Nolviko\Modules\Moderation\Infrastructure\DbalUserSanctions;
use Nolviko\Modules\Permissions\Domain\ForumTree;
use Nolviko\Modules\Permissions\Domain\ForumVisibility;
use Nolviko\Modules\Permissions\Domain\PermissionCatalog;
use Nolviko\Modules\Permissions\Domain\PermissionResolver;
use Nolviko\Modules\Permissions\Infrastructure\DbalPermissionRuleStore;
use Nolviko\Modules\Permissions\Infrastructure\PermissionCatalogSync;
use Nolviko\Modules\Posts\Domain\PostRenderer;
use Nolviko\Modules\Posts\Domain\PostRepository;
use Nolviko\Modules\Posts\Infrastructure\DbalPostRepository;
use Nolviko\Modules\Search\Http\SearchHandler;
use Nolviko\Modules\Search\Infrastructure\DbalPostSearch;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Seo\Http\FeedHandler;
use Nolviko\Modules\Seo\Http\SeoHandler;
use Nolviko\Modules\Seo\Infrastructure\Sitemaps;
use Nolviko\Modules\Tasks\Http\TaskAdminHandler;
use Nolviko\Modules\Tasks\Infrastructure\TaskAdministration;
use Nolviko\Modules\Threads\Domain\ContentPolicy;
use Nolviko\Modules\Threads\Domain\CreateReply;
use Nolviko\Modules\Threads\Domain\CreateThread;
use Nolviko\Modules\Threads\Domain\Drafts;
use Nolviko\Modules\Threads\Domain\EditPost;
use Nolviko\Modules\Threads\Domain\ModerateContent;
use Nolviko\Modules\Threads\Domain\ReadTracker;
use Nolviko\Modules\Threads\Domain\ThreadCommands;
use Nolviko\Modules\Threads\Domain\ThreadRepository;
use Nolviko\Modules\Threads\Http\CreateReplyHandler;
use Nolviko\Modules\Threads\Http\CreateThreadHandler;
use Nolviko\Modules\Threads\Http\EditPostHandler;
use Nolviko\Modules\Threads\Http\ForumReadHandler;
use Nolviko\Modules\Threads\Http\ForumThreadsHandler;
use Nolviko\Modules\Threads\Http\ModerationHandler;
use Nolviko\Modules\Threads\Http\PostHistoryHandler;
use Nolviko\Modules\Threads\Http\PostPermalinkHandler;
use Nolviko\Modules\Threads\Http\ThreadCommandHandler;
use Nolviko\Modules\Threads\Http\ThreadLabelsHandler;
use Nolviko\Modules\Threads\Http\ThreadPageHandler;
use Nolviko\Modules\Threads\Http\ThreadPostsHandler;
use Nolviko\Modules\Threads\Infrastructure\DbalDrafts;
use Nolviko\Modules\Threads\Infrastructure\DbalModerationWriter;
use Nolviko\Modules\Threads\Infrastructure\DbalPostEditor;
use Nolviko\Modules\Threads\Infrastructure\DbalReadTracker;
use Nolviko\Modules\Threads\Infrastructure\DbalReplyWriter;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadCommandWriter;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadRepository;
use Nolviko\Modules\Threads\Infrastructure\DbalThreadWriter;
use Nolviko\Modules\Threads\Infrastructure\PostingLock;
use Nolviko\Modules\Threads\Infrastructure\ThreadTaxonomy;
use Nolviko\Modules\Threads\Infrastructure\ThreadViews;
use Nolviko\Modules\Users\Domain\Authenticator;
use Nolviko\Modules\Users\Domain\LoginThrottle;
use Nolviko\Modules\Users\Domain\NoChallenge;
use Nolviko\Modules\Users\Domain\PasswordHasher;
use Nolviko\Modules\Users\Domain\RememberTokens;
use Nolviko\Modules\Users\Domain\SecondFactors;
use Nolviko\Modules\Users\Domain\UserRepository;
use Nolviko\Modules\Users\Http\AccountHandler;
use Nolviko\Modules\Users\Http\AuthSession;
use Nolviko\Modules\Users\Http\ExternalAuthHandler;
use Nolviko\Modules\Users\Http\InstallerAdminHandler;
use Nolviko\Modules\Users\Http\LoginHandler;
use Nolviko\Modules\Users\Http\LoginVerifyHandler;
use Nolviko\Modules\Users\Http\LogoutHandler;
use Nolviko\Modules\Users\Http\PasswordResetHandler;
use Nolviko\Modules\Users\Http\PrivacyHandler;
use Nolviko\Modules\Users\Http\QuestionChallenge;
use Nolviko\Modules\Users\Http\RegisterHandler;
use Nolviko\Modules\Users\Http\RequestSubject;
use Nolviko\Modules\Users\Http\SecondFactorLogin;
use Nolviko\Modules\Users\Http\SecondFactorSetupGuard;
use Nolviko\Modules\Users\Http\VerifyEmailHandler;
use Nolviko\Modules\Users\Http\WorkChallenge;
use Nolviko\Modules\Users\Infrastructure\AccountPrivacy;
use Nolviko\Modules\Users\Infrastructure\AccountRegistration;
use Nolviko\Modules\Users\Infrastructure\AccountSettings;
use Nolviko\Modules\Users\Infrastructure\DbalLoginAttempts;
use Nolviko\Modules\Users\Infrastructure\DbalRememberTokens;
use Nolviko\Modules\Users\Infrastructure\DbalResetTokens;
use Nolviko\Modules\Users\Infrastructure\DbalSessionEpochs;
use Nolviko\Modules\Users\Infrastructure\DbalThemePreference;
use Nolviko\Modules\Users\Infrastructure\DbalUserRepository;
use Nolviko\Modules\Users\Infrastructure\DbalVerificationTokens;
use Nolviko\Modules\Users\Infrastructure\ExternalIdentities;
use Nolviko\Modules\Users\Infrastructure\FirstAdminProvisioner;
use Nolviko\Modules\Users\Infrastructure\PasswordRecovery;
use Nolviko\Modules\Users\Infrastructure\PersonalDataExport;
use Nolviko\Modules\Users\Infrastructure\ResetMailQueue;
use Nolviko\Modules\Users\Infrastructure\TemplatedAccountMail;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Core services. Expects the CompositionRoot values (`app.root`, `app.storage`, `app.config`, the
 * InstallationState and an ErrorReporter).
 */
final class CoreServiceProvider implements ServiceProvider
{
    public function register(ContainerBuilder $services): void
    {
        $services->factory(
            LocaleContext::class,
            static fn(
                string $root,
                ?Config $config,
                InstallationState $state,
                Closure $auth,
                Closure $users,
                Closure $settings,
            ): LocaleContext => new LocaleContext(
                new LocaleResolver(TranslatorFactory::supportedLocales($root . '/translations')),
                static fn(Request $request): ?array => $state->isInstalled()
                    ? self::userPreferences($auth, $users, $request)
                    : null,
                // config/local.php wins; otherwise the value chosen in Admin > Site settings (2026-11-17).
                static fn(): ?string => $config?->defaultLocale
                    ?? ($state->isInstalled() ? $settings()->cached('locale.default') : null),
                static fn(): ?string => $config?->defaultTimeZone
                    ?? ($state->isInstalled() ? $settings()->cached('locale.time_zone') : null),
            ),
            [
                CompositionRoot::ROOT,
                CompositionRoot::CONFIG,
                InstallationState::class,
                new Lazy(AuthSession::class),
                new Lazy(UserRepository::class),
                new Lazy(SiteSettings::class),
            ],
        );
        $services->factory(
            LocaleFormatter::class,
            static fn(LocaleContext $locale): LocaleFormatter => new LocaleFormatter(
                $locale->locale(),
                $locale->timeZone(),
            ),
            [LocaleContext::class],
        );
        $services->factory(
            MessageTranslator::class,
            static fn(string $root, LocaleContext $locale, ExtensionRegistry $extensions): MessageTranslator
                => new RequestTranslator(
                    static fn(): MessageTranslator => $extensions->translator(TranslatorFactory::create(
                        $root . '/translations',
                        $locale->locale(),
                    )),
                ),
            [CompositionRoot::ROOT, LocaleContext::class, ExtensionRegistry::class],
        );
        $services->factory(
            Router::class,
            static fn(string $root, string $storage, ExtensionRegistry $extensions): Router
                => RouterFactory::create($root, $storage, $extensions),
            [CompositionRoot::ROOT, CompositionRoot::STORAGE, ExtensionRegistry::class],
        );
        $services->factory(
            CacheFactory::class,
            static fn(string $storage): CacheFactory => new CacheFactory($storage),
            [CompositionRoot::STORAGE],
        );
        $services->factory(
            NativeSession::class,
            static fn(string $storage): NativeSession => new NativeSession($storage),
            [CompositionRoot::STORAGE],
        );
        $services->factory(
            Session::class,
            static fn(NativeSession $session): Session => $session,
            [NativeSession::class],
        );
        $services->factory(
            CsrfTokens::class,
            static fn(Session $session): CsrfTokens => new CsrfTokens($session),
            [Session::class],
        );
        $services->factory(
            ThemeBuildStore::class,
            static fn(string $root, string $storage): ThemeBuildStore => new ThemeBuildStore($root, $storage),
            [CompositionRoot::ROOT, CompositionRoot::STORAGE],
        );
        $services->factory(
            ThemeRequest::class,
            static fn(Session $session, Closure $connection): ThemeRequest => new ThemeRequest(
                static function () use ($session, $connection): ?string {
                    $user = $session->get('_user_id');
                    return is_int($user) ? (new DbalThemePreference($connection()))->get($user) : null;
                },
            ),
            [Session::class, new Lazy(Connection::class)],
        );
        $services->factory(
            ThemePresentation::class,
            static fn(ThemeBuildStore $builds, Session $session, ThemeRequest $request): ThemePresentation
                => new ThemePresentation($builds, $session, $request),
            [ThemeBuildStore::class, Session::class, ThemeRequest::class],
        );
        $services->factory(
            ThemeSlots::class,
            static fn(Closure $subjects, ThemeRequest $request, ExtensionRegistry $extensions): ThemeSlots
                => $extensions->applySlots(new ThemeSlots(
                    static fn(string $key): bool => $request->request !== null
                    && $subjects()->of($request->request)->allows($key),
                )),
            [new Lazy(RequestSubject::class), ThemeRequest::class, ExtensionRegistry::class],
        );
        $services->factory(
            SiteUrl::class,
            static fn(?Config $config): SiteUrl => new SiteUrl($config?->appUrl),
            [CompositionRoot::CONFIG],
        );
        $services->factory(
            SeoMetadata::class,
            static fn(SiteUrl $site, ExtensionRegistry $extensions): SeoMetadata
                => new SeoMetadata($site, $extensions->pageMeta),
            [SiteUrl::class, ExtensionRegistry::class],
        );
        $services->factory(
            Sitemaps::class,
            static fn(
                Connection $db,
                ForumVisibility $visibility,
                PermissionResolver $permissions,
                SiteUrl $site,
                Router $urls,
                ExtensionRegistry $extensions,
            ): Sitemaps => new Sitemaps(
                $db,
                $visibility,
                $permissions->guest(...),
                $site,
                $urls,
                rules: $extensions->sitemaps,
            ),
            [Connection::class, ForumVisibility::class, PermissionResolver::class, SiteUrl::class, Router::class,
                ExtensionRegistry::class],
        );
        $services->factory(
            PostHistoryHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                PostRepository $posts,
                ThreadRepository $threads,
                UserRepository $users,
                ForumVisibility $visibility,
                Router $router,
                ErrorResponder $errors,
            ): PostHistoryHandler => new PostHistoryHandler(
                $translator,
                $views,
                $subjects,
                $posts,
                $threads,
                $users,
                $visibility,
                $router,
                $errors,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                PostRepository::class,
                ThreadRepository::class,
                UserRepository::class,
                ForumVisibility::class,
                Router::class,
                ErrorResponder::class,
            ],
        );
        $services->factory(
            FeedHandler::class,
            static fn(
                Connection $db,
                ThreadRepository $threads,
                ForumRepository $forums,
                ForumVisibility $visibility,
                PermissionResolver $permissions,
                SiteUrl $site,
                Router $urls,
                MessageTranslator $translator,
            ): FeedHandler => new FeedHandler(
                $db,
                $threads,
                $forums,
                $visibility,
                $permissions->guest(...),
                $site,
                $urls,
                $translator,
            ),
            [
                Connection::class,
                ThreadRepository::class,
                ForumRepository::class,
                ForumVisibility::class,
                PermissionResolver::class,
                SiteUrl::class,
                Router::class,
                MessageTranslator::class,
            ],
        );
        $services->factory(
            SeoHandler::class,
            static fn(
                SiteUrl $site,
                Router $urls,
                Closure $maps,
                ?Config $config,
                ExtensionRegistry $extensions,
            ): SeoHandler => new SeoHandler(
                $site,
                $urls,
                $maps,
                $config?->appSecret->reveal() ?? '',
                $extensions->robots,
            ),
            [SiteUrl::class, Router::class, new Lazy(Sitemaps::class), CompositionRoot::CONFIG,
                ExtensionRegistry::class],
        );
        $services->factory(
            TemplateRenderer::class,
            static function (
                string $root,
                string $storage,
                MessageTranslator $translator,
                CspNonce $nonce,
                Closure $formatter,
                Router $urls,
                ThemePresentation $presentation,
                ThemeSlots $slots,
                CacheFactory $caches,
                Closure $subjects,
                ThemeRequest $request,
                SeoMetadata $seo,
                Closure $notifications,
                Closure $conversations,
                Closure $siteSettings,
            ): TemplateRenderer {
                $selection = $presentation->selection();
                // Site accent colour (appearance.accent) over the theme's palette; part of the render-cache key.
                $accent = $request->request === null ? null : $siteSettings()->peek('appearance.accent');
                $selection['css'] .= ThemeTokens::accent($accent);
                $censored = $request->request === null ? [] : EffectivePostingRules::censored($siteSettings());
                return TwigFactory::create(
                    $root,
                    $storage,
                    $translator,
                    $nonce,
                    activeTheme: $selection['theme'],
                    formatter: $formatter,
                    assetBase: static fn(): string => $urls->generate(CoreRoutes::HOME),
                    themesDirectory: $selection['themes'],
                    coreViews: $selection['core'],
                    cacheIdentity: $selection['hash'],
                    themeSelection: $selection,
                    slots: $slots,
                    renderCache: $caches->namespace('theme-render'),
                    renderIdentity: static function () use (
                        $accent,
                        $censored,
                        $subjects,
                        $request,
                        $translator,
                        $selection,
                        $formatter,
                        $urls,
                        $root,
                    ): array {
                        if ($request->request === null || !$selection['cacheable']) {
                            return ['preview' => true];
                        }
                        $subject = $subjects()->of($request->request);
                        return ['user' => $subject->userId, 'policy' => $subject->cacheIdentity(),
                            'locale' => $translator->locale(), 'mode' => $selection['appearance'],
                            'zone' => $formatter()->timeZone->getName(), 'format_locale' => $formatter()->locale,
                            'base' => $urls->generate(CoreRoutes::HOME), 'root' => $root,
                            'theme' => $selection['hash'], 'accent' => $accent, 'censor' => $censored,
                            'preview' => $selection['preview'],
                            'bypass' => !$selection['cacheable'],
                            'method' => $request->request->getMethod()];
                    },
                    appearanceUrl: $urls->generate(CoreRoutes::APPEARANCE),
                    forumUrl: $urls->generate(CoreRoutes::HOME),
                    urls: $urls,
                    seo: $seo,
                    viewer: static function () use ($subjects, $request, $notifications, $conversations): array {
                        if ($request->request === null) {
                            return [];
                        }
                        $subject = $subjects()->of($request->request);
                        $member = $subject->userId !== null;
                        $moderator = $subject->allows('moderation.report.handle')
                            || $subject->forumsAllowing('moderation.report.handle') !== [];
                        $unread = ['notifications' => 0, 'messages' => 0];
                        if ($member) {
                            // Badges only: a missing column/table or DB hiccup must not break the page.
                            try {
                                $unread['notifications'] = $notifications()->unreadCount($subject);
                            } catch (Throwable) {
                                $unread['notifications'] = 0;
                            }
                            try {
                                $unread['messages'] = $conversations()->unreadCount((int) $subject->userId);
                            } catch (Throwable) {
                                $unread['messages'] = 0;
                            }
                        }
                        return ['signed_in' => $member, 'admin' => $member && $subject->allows('admin.access'),
                            'moderator' => $member && $moderator] + $unread;
                    },
                    censored: static fn(): array => $censored,
                    site: static fn(): array => [
                        'name' => $siteSettings()->peek('site.name') ?? '',
                        'description' => $siteSettings()->peek('site.description') ?? '',
                        'announcement' => $siteSettings()->peek('announcement.text') ?? '',
                        // Switches the layout and the editors read (2026-11-14), with their built-in defaults.
                        'gallery' => $siteSettings()->peek('gallery.enabled')
                            ?? SiteSettings::DEFAULTS['gallery.enabled'],
                        'post_images' => $siteSettings()->peek('posting.images')
                            ?? SiteSettings::DEFAULTS['posting.images'],
                        'images_per_post' => $siteSettings()->peek('posting.images_per_post')
                            ?? (string) PostingRules::DEFAULT_MAX_IMAGES,
                    ],
                );
            },
            [CompositionRoot::ROOT, CompositionRoot::STORAGE, MessageTranslator::class, CspNonce::class,
                new Lazy(LocaleFormatter::class), Router::class, ThemePresentation::class, ThemeSlots::class,
                CacheFactory::class, new Lazy(RequestSubject::class), ThemeRequest::class, SeoMetadata::class,
                new Lazy(CommunityNotifications::class), new Lazy(PrivateConversations::class),
                new Lazy(SiteSettings::class)],
        );
        $services->factory(
            AppearanceHandler::class,
            static fn(TemplateRenderer $views, MessageTranslator $translator, CsrfTokens $csrf, Router $urls,
                ThemeRequest $theme, RequestSubject $subjects, Connection $connection): AppearanceHandler
                => new AppearanceHandler(
                    $views,
                    $translator,
                    $csrf,
                    $urls,
                    $theme,
                    $subjects,
                    new DbalThemePreference($connection)
                ),
            [TemplateRenderer::class, MessageTranslator::class, CsrfTokens::class, Router::class,
                ThemeRequest::class, RequestSubject::class, Connection::class],
        );
        // Panel update checks for extensions and themes (2026-11-17): the core's cached GitHub release discovery.
        $services->factory(
            PackageUpdates::class,
            static fn(CacheFactory $caches, LoggerInterface $logger): PackageUpdates => new PackageUpdates(
                new GitHubReleaseDiscovery(
                    new NativeReleaseHttpClient(),
                    $caches->namespace('update-discovery'),
                    $logger,
                ),
            ),
            [CacheFactory::class, LoggerInterface::class],
        );
        $services->factory(
            ExtensionsHandler::class,
            static fn(ExtensionManager $manager, Connection $connection, RequestSubject $subjects,
                AdminSession $admin, CsrfTokens $csrf, TemplateRenderer $views, MessageTranslator $translator,
                Router $urls, ExtensionRegistry $extensions, PackageUpdates $updates): ExtensionsHandler
                => new ExtensionsHandler(
                    $manager,
                    new ExtensionMigrations($connection, $manager->catalog),
                    $subjects,
                    $admin,
                    $csrf,
                    $views,
                    $translator,
                    $urls,
                    new DbalAdminAudit($connection),
                    $extensions->adminPages,
                    $updates,
                    new PackageAssetDownloader(),
                ),
            [ExtensionManager::class, Connection::class, RequestSubject::class, AdminSession::class,
                CsrfTokens::class, TemplateRenderer::class, MessageTranslator::class,
                Router::class, ExtensionRegistry::class, PackageUpdates::class],
        );
        $services->factory(
            ThemesHandler::class,
            static fn(ThemeBuildStore $builds, ThemePresentation $presentation, RequestSubject $subjects,
                AdminSession $admin, CsrfTokens $csrf, Session $session, TemplateRenderer $views,
                MessageTranslator $translator, Router $urls, Connection $connection,
                PackageUpdates $updates): ThemesHandler
                => new ThemesHandler(
                    $builds,
                    $presentation,
                    $subjects,
                    $admin,
                    $csrf,
                    $session,
                    $views,
                    $translator,
                    $urls,
                    new ThemeArchive($builds),
                    new DbalAdminAudit($connection),
                    $updates,
                    new PackageAssetDownloader(),
                ),
            [ThemeBuildStore::class, ThemePresentation::class, RequestSubject::class, AdminSession::class,
                CsrfTokens::class, Session::class, TemplateRenderer::class, MessageTranslator::class,
                Router::class, Connection::class, PackageUpdates::class],
        );
        $services->factory(
            Connection::class,
            static fn(?Config $config): Connection => ConnectionFactory::create($config),
            [CompositionRoot::CONFIG],
        );
        $services->factory(
            ErrorResponder::class,
            static fn(MessageTranslator $translator, Router $router): ErrorResponder => new ErrorResponder(
                $translator,
                static fn(): string => $router->generate(CoreRoutes::HOME),
            ),
            [MessageTranslator::class, Router::class],
        );
        $services->factory(
            PrerequisiteCheck::class,
            static fn(string $root, string $storage, ?Config $config): PrerequisiteCheck => new PrerequisiteCheck(
                $root,
                $storage,
                new WebExposureProbe(),
                $config?->databaseDriver,
            ),
            [CompositionRoot::ROOT, CompositionRoot::STORAGE, CompositionRoot::CONFIG],
        );
        $services->factory(ResponseCookies::class, static fn(): ResponseCookies => new ResponseCookies());
        $services->factory(
            ForumTree::class,
            static fn(Connection $connection): ForumTree => new DbalForumTree($connection),
            [Connection::class],
        );
        $services->factory(
            ForumVisibility::class,
            static fn(CacheFactory $caches, Closure $connection): ForumVisibility => new ForumVisibility(
                $caches->namespace('permissions'),
                // Read lazily: only a cache lookup needs the version, and only then the connection.
                static fn(): int => (new VersionCounter($connection()))->current(VersionCounter::PERMISSION_RULES),
            ),
            [CacheFactory::class, new Lazy(Connection::class)],
        );
        $services->factory(
            PostRepository::class,
            static fn(Connection $connection): PostRepository => new DbalPostRepository($connection),
            [Connection::class],
        );
        $services->factory(
            PostRenderer::class,
            static fn(PostRepository $posts, Closure $settings, Router $router): PostRenderer => new PostRenderer(
                $posts,
                censoredWords: static fn(): array => EffectivePostingRules::censored($settings()),
                mentionUrl: static fn(string $name): string => $router->generate(CoreRoutes::MEMBER_LINK)
                    . '?name=' . rawurlencode($name),
            ),
            [PostRepository::class, new Lazy(SiteSettings::class), Router::class],
        );
        $services->factory(
            ThreadRepository::class,
            static fn(Connection $connection): ThreadRepository => new DbalThreadRepository($connection),
            [Connection::class],
        );
        $services->factory(
            ForumRepository::class,
            static fn(Connection $connection, ?Config $config): ForumRepository => new DbalForumRepository(
                $connection,
                $config?->forumMaxDepth ?? DbalForumRepository::DEFAULT_MAX_DEPTH,
            ),
            [Connection::class, CompositionRoot::CONFIG],
        );
        $services->factory(
            PermissionResolver::class,
            static fn(Connection $connection, PermissionCatalog $catalog, ForumTree $forums): PermissionResolver
                => new PermissionResolver($catalog, new DbalPermissionRuleStore($connection, $catalog), $forums),
            [Connection::class, PermissionCatalog::class, ForumTree::class],
        );
        $services->factory(
            PermissionCatalog::class,
            // Strict (unknown keys throw) in a development checkout, which has no release file.
            static fn(string $root, ExtensionRegistry $extensions): PermissionCatalog => new PermissionCatalog(
                [...array_values(PermissionCatalog::core()->all()), ...array_values($extensions->permissions)],
                ReleaseId::fromFile($root . '/config/release.php') === null,
            ),
            [CompositionRoot::ROOT, ExtensionRegistry::class],
        );
        $services->factory(PasswordHasher::class, static fn(): PasswordHasher => new PasswordHasher());
        $services->factory(
            UserRepository::class,
            static fn(Connection $connection): UserRepository => new DbalUserRepository($connection),
            [Connection::class],
        );
        $services->factory(
            RememberTokens::class,
            static fn(Connection $connection): RememberTokens => new DbalRememberTokens($connection),
            [Connection::class],
        );
        $services->factory(
            Authenticator::class,
            static fn(UserRepository $users, PasswordHasher $hasher): Authenticator
                => new Authenticator($users, $hasher),
            [UserRepository::class, PasswordHasher::class],
        );
        $services->factory(
            AuthSession::class,
            static fn(
                Session $session,
                CsrfTokens $csrf,
                RememberTokens $remember,
                ResponseCookies $cookies,
                Connection $connection,
            ): AuthSession => new AuthSession($session, $csrf, $remember, $cookies, new DbalSessionEpochs($connection)),
            [Session::class, CsrfTokens::class, RememberTokens::class, ResponseCookies::class, Connection::class],
        );
        $services->factory(
            LoginThrottle::class,
            static fn(Connection $connection): LoginThrottle => new LoginThrottle(new DbalLoginAttempts($connection)),
            [Connection::class],
        );
        $services->factory(
            LoginHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                Authenticator $authenticator,
                AuthSession $auth,
                CsrfTokens $csrf,
                Router $router,
                LoginThrottle $throttle,
                Connection $connection,
                SecondFactorLogin $secondFactor,
                Closure $identityProviders,
            ): LoginHandler => new LoginHandler(
                $translator,
                $views,
                $authenticator,
                $auth,
                $csrf,
                $router,
                $throttle,
                new DbalUserSanctions($connection, new Outbox($connection)),
                new DbalBanList($connection, new Outbox($connection)),
                $secondFactor,
                static fn(): array => ExternalAuthHandler::buttons($identityProviders(), $router, $translator),
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                Authenticator::class,
                AuthSession::class,
                CsrfTokens::class,
                Router::class,
                LoginThrottle::class,
                Connection::class,
                SecondFactorLogin::class,
                new Lazy(IdentityProviders::class),
            ],
        );
        // Sign in with an outside account (2026-11-15): one handler class, four routes.
        foreach (
            [ExternalAuthHandler::START, ExternalAuthHandler::CALLBACK, ExternalAuthHandler::REGISTER,
                ExternalAuthHandler::UNLINK, ExternalAuthHandler::LINK] as $externalMode
        ) {
            $services->factory(
                ExternalAuthHandler::class . '.' . $externalMode,
                static fn(
                    MessageTranslator $translator,
                    TemplateRenderer $views,
                    IdentityProviders $providers,
                    Connection $connection,
                    AuthSession $auth,
                    Session $session,
                    CsrfTokens $csrf,
                    Router $router,
                    SiteUrl $site,
                    UserRepository $users,
                    PasswordHasher $hasher,
                    LoginThrottle $throttle,
                    ErrorResponder $errors,
                    Closure $registration,
                    Closure $secondFactor,
                ): ExternalAuthHandler => new ExternalAuthHandler(
                    $externalMode,
                    $translator,
                    $views,
                    $providers,
                    new ExternalIdentities($connection),
                    $auth,
                    $session,
                    $csrf,
                    $router,
                    $site,
                    $users,
                    $hasher,
                    $throttle,
                    $errors,
                    $registration,
                    $secondFactor,
                    new DbalUserSanctions($connection, new Outbox($connection)),
                    new DbalBanList($connection, new Outbox($connection)),
                ),
                [
                    MessageTranslator::class, TemplateRenderer::class, IdentityProviders::class, Connection::class,
                    AuthSession::class, Session::class, CsrfTokens::class, Router::class, SiteUrl::class,
                    UserRepository::class, PasswordHasher::class, LoginThrottle::class, ErrorResponder::class,
                    new Lazy(AccountRegistration::class), new Lazy(SecondFactorLogin::class),
                ],
            );
        }
        // Second sign-in step (2026-11-15, ADR-029): core storage and session flow; providers come from extensions.
        $services->factory(
            SecondFactors::class,
            static fn(
                Connection $connection,
                SecondFactorProviders $providers,
                ?Config $config,
                UserRepository $users,
                Mailer $mailer,
                MailComposer $composer,
                Router $router,
            ): SecondFactors => new SecondFactors(
                $connection,
                $config === null ? new SecondFactorProviders() : $providers,
                $config?->appSecret ?? new Secret(''),
                static function (int $userId, string $event) use ($users, $mailer, $composer, $router, $config): void {
                    $user = $users->findById($userId);
                    if ($user !== null) {
                        (new TemplatedAccountMail($mailer, $composer, $router, $config?->appUrl))
                            ->secondFactorNotice($user->email, $user->username, $event);
                    }
                },
            ),
            [
                Connection::class,
                SecondFactorProviders::class,
                CompositionRoot::CONFIG,
                UserRepository::class,
                Mailer::class,
                MailComposer::class,
                Router::class,
            ],
        );
        $services->factory(
            SecondFactorLogin::class,
            static fn(
                Session $session,
                SecondFactors $factors,
                UsergroupRepository $groups,
                PermissionResolver $resolver,
            ): SecondFactorLogin => new SecondFactorLogin($session, $factors, $groups, $resolver),
            [Session::class, SecondFactors::class, UsergroupRepository::class, PermissionResolver::class],
        );
        $services->factory(
            LoginVerifyHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                SecondFactorLogin $secondFactor,
                AuthSession $auth,
                UserRepository $users,
                CsrfTokens $csrf,
                Router $router,
                LoginThrottle $throttle,
            ): LoginVerifyHandler => new LoginVerifyHandler(
                $translator,
                $views,
                $secondFactor,
                $auth,
                $users,
                $csrf,
                $router,
                $throttle,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                SecondFactorLogin::class,
                AuthSession::class,
                UserRepository::class,
                CsrfTokens::class,
                Router::class,
                LoginThrottle::class,
            ],
        );
        $services->factory(
            SecondFactorSetupGuard::class,
            static fn(Closure $session, Closure $auth, Closure $login, Closure $router): SecondFactorSetupGuard
                => new SecondFactorSetupGuard($session, $auth, $login, $router),
            [new Lazy(Session::class), new Lazy(AuthSession::class), new Lazy(SecondFactorLogin::class),
                new Lazy(Router::class)],
        );
        $services->factory(
            UsergroupRepository::class,
            static fn(Connection $connection): UsergroupRepository => new DbalUsergroupRepository($connection),
            [Connection::class],
        );
        $services->factory(
            RequestSubject::class,
            static fn(
                AuthSession $auth,
                UsergroupRepository $groups,
                PermissionResolver $resolver,
                Connection $connection,
            ): RequestSubject => new RequestSubject(
                $auth,
                $groups,
                $resolver,
                new DbalUserSanctions($connection, new Outbox($connection)),
                new DbalBanList($connection, new Outbox($connection)),
            ),
            [AuthSession::class, UsergroupRepository::class, PermissionResolver::class, Connection::class],
        );
        $services->factory(
            PostingRules::class,
            static fn(?Config $config, Closure $settings): PostingRules
                => EffectivePostingRules::of($config, $settings()),
            [CompositionRoot::CONFIG, new Lazy(SiteSettings::class)],
        );
        $services->factory(
            CreateThread::class,
            static fn(
                Connection $connection,
                ForumRepository $forums,
                ForumVisibility $visibility,
                PostRepository $posts,
                ThreadRepository $threads,
                ?Config $config,
                BeforeHooks $before,
                PostingRules $rules,
            ): CreateThread => new CreateThread(
                $forums,
                $visibility,
                $posts,
                new DbalThreadWriter(
                    $connection,
                    $threads,
                    new Outbox($connection),
                    new PostingLock($connection),
                ),
                networkSeconds: $config?->postingIpInterval ?? CreateThread::NETWORK_INTERVAL,
                before: $before,
                policy: new ContentPolicy(
                    $rules,
                    $posts,
                    new DbalUserRepository($connection),
                    siteHost: is_string($config?->appUrl) ? parse_url($config->appUrl, PHP_URL_HOST) ?: null : null,
                ),
            ),
            [
                Connection::class,
                ForumRepository::class,
                ForumVisibility::class,
                PostRepository::class,
                ThreadRepository::class,
                CompositionRoot::CONFIG,
                BeforeHooks::class,
                PostingRules::class,
            ],
        );
        $services->factory(
            CreateReply::class,
            static fn(
                Connection $connection,
                ThreadRepository $threads,
                ForumVisibility $visibility,
                PostRepository $posts,
                ?Config $config,
                BeforeHooks $before,
                PostingRules $rules,
            ): CreateReply => new CreateReply(
                $threads,
                $visibility,
                $posts,
                new DbalReplyWriter($connection, new Outbox($connection), new PostingLock($connection)),
                networkSeconds: $config?->postingIpInterval ?? CreateReply::NETWORK_INTERVAL,
                before: $before,
                policy: new ContentPolicy(
                    $rules,
                    $posts,
                    new DbalUserRepository($connection),
                    siteHost: is_string($config?->appUrl) ? parse_url($config->appUrl, PHP_URL_HOST) ?: null : null,
                ),
            ),
            [
                Connection::class,
                ThreadRepository::class,
                ForumVisibility::class,
                PostRepository::class,
                CompositionRoot::CONFIG,
                BeforeHooks::class,
                PostingRules::class,
            ],
        );
        $services->factory(
            CreateReplyHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                CreateReply $replies,
                CsrfTokens $csrf,
                Router $router,
                ErrorResponder $errors,
                Drafts $drafts,
                PostRepository $posts,
                UserRepository $users,
            ): CreateReplyHandler => new CreateReplyHandler(
                $translator,
                $views,
                $subjects,
                $replies,
                $csrf,
                $router,
                $errors,
                $drafts,
                posts: $posts,
                users: $users,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                CreateReply::class,
                CsrfTokens::class,
                Router::class,
                ErrorResponder::class,
                Drafts::class,
                PostRepository::class,
                UserRepository::class,
            ],
        );
        $services->factory(
            EditPost::class,
            static fn(
                Connection $connection,
                PostRepository $posts,
                ThreadRepository $threads,
                ForumVisibility $visibility,
                ?Config $config,
                PostingRules $rules,
            ): EditPost => new EditPost(
                $posts,
                $threads,
                $visibility,
                new DbalPostEditor($connection, $posts, new Outbox($connection)),
                policy: new ContentPolicy(
                    $rules,
                    $posts,
                    new DbalUserRepository($connection),
                    siteHost: is_string($config?->appUrl) ? parse_url($config->appUrl, PHP_URL_HOST) ?: null : null,
                ),
            ),
            [
                Connection::class,
                PostRepository::class,
                ThreadRepository::class,
                ForumVisibility::class,
                CompositionRoot::CONFIG,
                PostingRules::class,
            ],
        );
        $services->factory(
            EditPostHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                EditPost $edits,
                CsrfTokens $csrf,
                Router $router,
                ErrorResponder $errors,
            ): EditPostHandler => new EditPostHandler($translator, $views, $subjects, $edits, $csrf, $router, $errors),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                EditPost::class,
                CsrfTokens::class,
                Router::class,
                ErrorResponder::class,
            ],
        );
        $services->factory(
            ModerateContent::class,
            static fn(
                Connection $connection,
                PostRepository $posts,
                ThreadRepository $threads,
                ForumVisibility $visibility,
            ): ModerateContent => new ModerateContent(
                $posts,
                $threads,
                $visibility,
                new DbalModerationWriter($connection, new Outbox($connection)),
            ),
            [Connection::class, PostRepository::class, ThreadRepository::class, ForumVisibility::class],
        );
        $services->factory(
            ModerationHandler::class . '.post',
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                ModerateContent $moderation,
                CsrfTokens $csrf,
                Router $router,
                ErrorResponder $errors,
            ): ModerationHandler => new ModerationHandler(
                ModerationHandler::POST,
                $translator,
                $views,
                $subjects,
                $moderation,
                $csrf,
                $router,
                $errors,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                ModerateContent::class,
                CsrfTokens::class,
                Router::class,
                ErrorResponder::class,
            ],
        );
        $services->factory(
            ModerationHandler::class . '.thread',
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                ModerateContent $moderation,
                CsrfTokens $csrf,
                Router $router,
                ErrorResponder $errors,
            ): ModerationHandler => new ModerationHandler(
                ModerationHandler::THREAD,
                $translator,
                $views,
                $subjects,
                $moderation,
                $csrf,
                $router,
                $errors,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                ModerateContent::class,
                CsrfTokens::class,
                Router::class,
                ErrorResponder::class,
            ],
        );
        $services->factory(
            ThreadCommands::class,
            static fn(
                Connection $connection,
                ThreadRepository $threads,
                ForumRepository $forums,
                ForumVisibility $visibility,
            ): ThreadCommands => new ThreadCommands(
                $threads,
                $forums,
                $visibility,
                new DbalThreadCommandWriter($connection, new Outbox($connection)),
            ),
            [Connection::class, ThreadRepository::class, ForumRepository::class, ForumVisibility::class],
        );
        $services->factory(
            ThreadCommandHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                ThreadCommands $commands,
                CsrfTokens $csrf,
                Router $router,
                ErrorResponder $errors,
                ForumRepository $forums,
                ForumVisibility $visibility,
            ): ThreadCommandHandler => new ThreadCommandHandler(
                $translator,
                $views,
                $subjects,
                $commands,
                $csrf,
                $router,
                $errors,
                $forums,
                $visibility,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                ThreadCommands::class,
                CsrfTokens::class,
                Router::class,
                ErrorResponder::class,
                ForumRepository::class,
                ForumVisibility::class,
            ],
        );
        $services->factory(
            MemberSuggestHandler::class,
            static fn(Connection $db, RequestSubject $subjects, ForumVisibility $visibility): MemberSuggestHandler
                => new MemberSuggestHandler($db, $subjects, $visibility),
            [Connection::class, RequestSubject::class, ForumVisibility::class],
        );
        $services->factory(
            MemberLinkHandler::class,
            static fn(UserRepository $users, Router $router, ErrorResponder $errors): MemberLinkHandler
                => new MemberLinkHandler($users, $router, $errors),
            [UserRepository::class, Router::class, ErrorResponder::class],
        );
        $services->factory(
            Gallery::class,
            static fn(Connection $db, string $storage, string $root): Gallery
                => new Gallery($db, new GalleryImages(), $storage, $root . '/public'),
            [Connection::class, CompositionRoot::STORAGE, CompositionRoot::ROOT],
        );
        $services->factory(
            GalleryHandler::class,
            static fn(
                Gallery $gallery,
                SiteSettings $settings,
                RequestSubject $subjects,
                CsrfTokens $csrf,
                Session $session,
                MessageTranslator $translator,
                TemplateRenderer $views,
                Router $router,
                ErrorResponder $errors,
            ): GalleryHandler => new GalleryHandler(
                $gallery,
                $settings,
                $subjects,
                $csrf,
                $session,
                $translator,
                $views,
                $router,
                $errors,
            ),
            [
                Gallery::class,
                SiteSettings::class,
                RequestSubject::class,
                CsrfTokens::class,
                Session::class,
                MessageTranslator::class,
                TemplateRenderer::class,
                Router::class,
                ErrorResponder::class,
            ],
        );
        $services->factory(
            ApiHandler::class,
            static fn(
                ForumIndex $index,
                ForumVisibility $visibility,
                PermissionResolver $permissions,
                ThreadRepository $threads,
                PostRepository $posts,
                PostRenderer $renderer,
                Router $router,
                SiteUrl $site,
                SiteSettings $settings,
                MessageTranslator $translator,
            ): ApiHandler => new ApiHandler(
                $index,
                $visibility,
                $permissions->guest(...),
                $threads,
                $posts,
                $renderer,
                $router,
                $site,
                $settings,
                $translator,
            ),
            [
                ForumIndex::class,
                ForumVisibility::class,
                PermissionResolver::class,
                ThreadRepository::class,
                PostRepository::class,
                PostRenderer::class,
                Router::class,
                SiteUrl::class,
                SiteSettings::class,
                MessageTranslator::class,
            ],
        );
        $services->factory(
            WebhooksAdminHandler::class,
            static fn(
                Connection $db,
                ForumVisibility $visibility,
                PermissionResolver $permissions,
                ?Config $config,
                RequestSubject $subjects,
                AdminSession $admin,
                CsrfTokens $csrf,
                Session $session,
                MessageTranslator $translator,
                TemplateRenderer $views,
                Router $router,
            ): WebhooksAdminHandler => new WebhooksAdminHandler(
                new Webhooks(
                    $db,
                    static fn() => $visibility->readable($permissions->guest()),
                    $config?->appUrl ?? '',
                ),
                $subjects,
                $admin,
                $csrf,
                $session,
                $translator,
                $views,
                $router,
                new DbalAdminAudit($db),
            ),
            [
                Connection::class,
                ForumVisibility::class,
                PermissionResolver::class,
                CompositionRoot::CONFIG,
                RequestSubject::class,
                AdminSession::class,
                CsrfTokens::class,
                Session::class,
                MessageTranslator::class,
                TemplateRenderer::class,
                Router::class,
            ],
        );
        $services->factory(
            MemberStanding::class,
            static fn(Connection $db): MemberStanding => new MemberStanding($db),
            [Connection::class],
        );
        $services->factory(
            MemberExtrasAdminHandler::class,
            static fn(
                MemberStanding $standing,
                RequestSubject $subjects,
                AdminSession $admin,
                CsrfTokens $csrf,
                Session $session,
                MessageTranslator $translator,
                TemplateRenderer $views,
                Router $router,
                Connection $db,
            ): MemberExtrasAdminHandler => new MemberExtrasAdminHandler(
                $standing,
                $subjects,
                $admin,
                $csrf,
                $session,
                $translator,
                $views,
                $router,
                new DbalAdminAudit($db),
            ),
            [
                MemberStanding::class,
                RequestSubject::class,
                AdminSession::class,
                CsrfTokens::class,
                Session::class,
                MessageTranslator::class,
                TemplateRenderer::class,
                Router::class,
                Connection::class,
            ],
        );
        $services->factory(
            ThreadTaxonomy::class,
            static fn(Connection $db): ThreadTaxonomy => new ThreadTaxonomy($db),
            [Connection::class],
        );
        $services->factory(
            ThreadLabelsHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                ThreadRepository $threads,
                ThreadTaxonomy $taxonomy,
                ForumVisibility $visibility,
                CsrfTokens $csrf,
                Router $router,
                ErrorResponder $errors,
            ): ThreadLabelsHandler => new ThreadLabelsHandler(
                $translator,
                $views,
                $subjects,
                $threads,
                $taxonomy,
                $visibility,
                $csrf,
                $router,
                $errors,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                ThreadRepository::class,
                ThreadTaxonomy::class,
                ForumVisibility::class,
                CsrfTokens::class,
                Router::class,
                ErrorResponder::class,
            ],
        );
        $services->factory(
            TagPageHandler::class,
            static fn(
                RequestSubject $subjects,
                ForumVisibility $visibility,
                ForumIndex $index,
                ThreadTaxonomy $taxonomy,
                DiscussionFeed $feed,
                ErrorResponder $errors,
            ): TagPageHandler => new TagPageHandler($subjects, $visibility, $index, $taxonomy, $feed, $errors),
            [
                RequestSubject::class,
                ForumVisibility::class,
                ForumIndex::class,
                ThreadTaxonomy::class,
                DiscussionFeed::class,
                ErrorResponder::class,
            ],
        );
        $services->factory(
            TaxonomyAdminHandler::class,
            static fn(
                ThreadTaxonomy $taxonomy,
                ForumRepository $forums,
                RequestSubject $subjects,
                AdminSession $admin,
                CsrfTokens $csrf,
                Session $session,
                MessageTranslator $translator,
                TemplateRenderer $views,
                Router $router,
            ): TaxonomyAdminHandler => new TaxonomyAdminHandler(
                $taxonomy,
                $forums,
                $subjects,
                $admin,
                $csrf,
                $session,
                $translator,
                $views,
                $router,
            ),
            [
                ThreadTaxonomy::class,
                ForumRepository::class,
                RequestSubject::class,
                AdminSession::class,
                CsrfTokens::class,
                Session::class,
                MessageTranslator::class,
                TemplateRenderer::class,
                Router::class,
            ],
        );
        $services->factory(
            ThreadPostsHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                ThreadCommands $commands,
                ThreadRepository $threads,
                PostRepository $posts,
                ForumRepository $forums,
                ForumVisibility $visibility,
                CsrfTokens $csrf,
                Router $router,
                ErrorResponder $errors,
            ): ThreadPostsHandler => new ThreadPostsHandler(
                $translator,
                $views,
                $subjects,
                $commands,
                $threads,
                $posts,
                $forums,
                $visibility,
                $csrf,
                $router,
                $errors,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                ThreadCommands::class,
                ThreadRepository::class,
                PostRepository::class,
                ForumRepository::class,
                ForumVisibility::class,
                CsrfTokens::class,
                Router::class,
                ErrorResponder::class,
            ],
        );
        $services->factory(
            ForumIndex::class,
            static fn(Connection $connection): ForumIndex => new DbalForumIndex($connection),
            [Connection::class],
        );
        $services->factory(
            ForumIndexHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                ForumVisibility $visibility,
                ForumIndex $index,
                Router $router,
                SiteSettings $settings,
                DiscussionFeed $feed,
            ): ForumIndexHandler => new ForumIndexHandler(
                $translator,
                $views,
                $subjects,
                $visibility,
                $index,
                $router,
                $settings,
                $feed,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                ForumVisibility::class,
                ForumIndex::class,
                Router::class,
                SiteSettings::class,
                DiscussionFeed::class,
            ],
        );
        $services->factory(
            DiscussionFeed::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                ThreadRepository $threads,
                Router $router,
                ErrorResponder $errors,
                ReadTracker $reads,
                ThreadTaxonomy $taxonomy,
                CommunityRead $community,
            ): DiscussionFeed => new DiscussionFeed(
                $translator,
                $views,
                $threads,
                $router,
                $errors,
                $reads,
                taxonomy: $taxonomy,
                community: $community,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                ThreadRepository::class,
                Router::class,
                ErrorResponder::class,
                ReadTracker::class,
                ThreadTaxonomy::class,
                CommunityRead::class,
            ],
        );
        $services->factory(
            ThreadPageHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                ThreadRepository $threads,
                PostRepository $posts,
                PostRenderer $renderer,
                ForumVisibility $visibility,
                Router $router,
                ErrorResponder $errors,
                ?Config $config,
                ReadTracker $reads,
                CommunityRead $community,
                ForumRepository $forums,
                CsrfTokens $csrf,
                ThreadViews $viewCounter,
                ThreadTaxonomy $taxonomy,
                MemberStanding $standing,
            ): ThreadPageHandler => new ThreadPageHandler(
                $translator,
                $views,
                $subjects,
                $threads,
                $posts,
                $renderer,
                $visibility,
                $router,
                $errors,
                $config?->threadsPerPage ?? ThreadPageHandler::DEFAULT_PER_PAGE,
                $reads,
                $community,
                $forums,
                $csrf,
                $viewCounter,
                taxonomy: $taxonomy,
                standing: $standing,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                ThreadRepository::class,
                PostRepository::class,
                PostRenderer::class,
                ForumVisibility::class,
                Router::class,
                ErrorResponder::class,
                CompositionRoot::CONFIG,
                ReadTracker::class,
                CommunityRead::class,
                ForumRepository::class,
                CsrfTokens::class,
                ThreadViews::class,
                ThreadTaxonomy::class,
                MemberStanding::class,
            ],
        );
        $services->factory(
            ThreadViews::class,
            static fn(Connection $db, Session $session): ThreadViews => new ThreadViews($db, $session),
            [Connection::class, Session::class],
        );
        $services->factory(
            Drafts::class,
            static fn(Connection $connection): Drafts => new DbalDrafts($connection),
            [Connection::class],
        );
        $services->factory(
            ReadTracker::class,
            static fn(Connection $connection): ReadTracker => new DbalReadTracker($connection),
            [Connection::class],
        );
        $services->factory(
            ForumReadHandler::class,
            static fn(
                RequestSubject $subjects,
                ForumVisibility $visibility,
                ReadTracker $reads,
                Router $router,
                ErrorResponder $errors,
            ): ForumReadHandler => new ForumReadHandler($subjects, $visibility, $reads, $router, $errors),
            [RequestSubject::class, ForumVisibility::class, ReadTracker::class, Router::class, ErrorResponder::class],
        );
        $services->factory(
            ForumThreadsHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                ForumRepository $forums,
                ThreadRepository $threads,
                ForumVisibility $visibility,
                Router $router,
                ErrorResponder $errors,
                ReadTracker $reads,
                CsrfTokens $csrf,
                ThreadTaxonomy $taxonomy,
                CommunityRead $community,
            ): ForumThreadsHandler => new ForumThreadsHandler(
                $translator,
                $views,
                $subjects,
                $forums,
                $threads,
                $visibility,
                $router,
                $errors,
                $reads,
                $csrf,
                taxonomy: $taxonomy,
                community: $community,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                ForumRepository::class,
                ThreadRepository::class,
                ForumVisibility::class,
                Router::class,
                ErrorResponder::class,
                ReadTracker::class,
                CsrfTokens::class,
                ThreadTaxonomy::class,
                CommunityRead::class,
            ],
        );
        $services->factory(
            PostPermalinkHandler::class,
            static fn(
                RequestSubject $subjects,
                ThreadRepository $threads,
                PostRepository $posts,
                ForumVisibility $visibility,
                Router $router,
                ErrorResponder $errors,
                ?Config $config,
            ): PostPermalinkHandler => new PostPermalinkHandler(
                $subjects,
                $threads,
                $posts,
                $visibility,
                $router,
                $errors,
                $config?->threadsPerPage ?? ThreadPageHandler::DEFAULT_PER_PAGE,
            ),
            [
                RequestSubject::class,
                ThreadRepository::class,
                PostRepository::class,
                ForumVisibility::class,
                Router::class,
                ErrorResponder::class,
                CompositionRoot::CONFIG,
            ],
        );
        $services->factory(
            AdminSession::class,
            static fn(Session $session): AdminSession => new AdminSession($session),
            [Session::class],
        );
        $services->factory(
            AdminGuard::class,
            static fn(Closure $subjects, Router $router, ErrorResponder $errors): AdminGuard
                => new AdminGuard($subjects, $router, $errors),
            [new Lazy(RequestSubject::class), Router::class, ErrorResponder::class],
        );
        $services->factory(
            DashboardHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                Connection $connection,
                AdminSession $admin,
                Router $router,
                string $root,
                string $storage,
                ?Config $config,
                CacheFactory $caches,
                CsrfTokens $csrf,
                RequestSubject $subjects,
                Closure $factors,
            ): DashboardHandler => new DashboardHandler(
                $translator,
                $views,
                new DbalAdminStats($connection),
                $admin,
                $router,
                new PostInstallExposure($root, $storage, $config?->appUrl, $caches->namespace('security')),
                $csrf,
                $subjects,
                $factors,
            ),
            [MessageTranslator::class, TemplateRenderer::class, Connection::class, AdminSession::class, Router::class,
                CompositionRoot::ROOT, CompositionRoot::STORAGE, CompositionRoot::CONFIG, CacheFactory::class,
                CsrfTokens::class, RequestSubject::class, new Lazy(SecondFactors::class)],
        );
        $services->factory(
            LegacyHandler::class,
            static fn(Connection $db, ForumVisibility $visibility, Router $router, RequestSubject $subjects,
                ErrorResponder $errors): LegacyHandler => new LegacyHandler(new LegacyRedirects(
                    $db,
                    $visibility,
                    $router
                ), $subjects, $errors),
            [Connection::class, ForumVisibility::class, Router::class, RequestSubject::class, ErrorResponder::class],
        );
        $services->factory(
            ImportFileStore::class,
            static fn(string $storage, string $root): ImportFileStore => new ImportFileStore(
                $storage,
                $root . '/public'
            ),
            [CompositionRoot::STORAGE, CompositionRoot::ROOT],
        );
        $services->factory(
            PrivateImportFiles::class,
            static fn(Connection $db, ImportFileStore $files): PrivateImportFiles
                => new PrivateImportFiles($db, $files),
            [Connection::class, ImportFileStore::class],
        );
        $services->factory(
            ImportAdministration::class,
            static fn(Connection $db, string $root, ?Config $config, ImportFileStore $files,
                Router $router): ImportAdministration
                => new ImportAdministration(
                    $db,
                    new ImportSources(
                        $root,
                        $config?->appSecret ?? throw new RuntimeException('import_unavailable')
                    ),
                    $files,
                    new InlineAttachments($db, $router, $config?->appUrl ?? '')
                ),
            [Connection::class, CompositionRoot::ROOT, CompositionRoot::CONFIG, ImportFileStore::class, Router::class],
        );
        $services->factory(
            SystemDiagnostics::class,
            static fn(Connection $db, string $root, string $storage, SiteUrl $site): SystemDiagnostics
                => new SystemDiagnostics($db, $root, $storage, $site),
            [Connection::class, CompositionRoot::ROOT, CompositionRoot::STORAGE, SiteUrl::class],
        );
        $services->factory(
            ImportsHandler::class,
            static fn(ImportAdministration $imports, RequestSubject $subjects, AdminSession $admin,
                CsrfTokens $csrf, Connection $db, TemplateRenderer $views, MessageTranslator $translator,
                Router $router): ImportsHandler => new ImportsHandler(
                    $imports,
                    $subjects,
                    $admin,
                    $csrf,
                    new DbalAdminAudit($db),
                    $views,
                    $translator,
                    $router
                ),
            [ImportAdministration::class, RequestSubject::class, AdminSession::class, CsrfTokens::class,
                Connection::class, TemplateRenderer::class, MessageTranslator::class, Router::class],
        );
        $services->factory(
            DiagnosticsHandler::class,
            static fn(SystemDiagnostics $diagnostics, RequestSubject $subjects, AdminSession $admin,
                CsrfTokens $csrf, Connection $db, TemplateRenderer $views, MessageTranslator $translator,
                Router $router): DiagnosticsHandler => new DiagnosticsHandler(
                    $diagnostics,
                    $subjects,
                    $admin,
                    $csrf,
                    new DbalAdminAudit($db),
                    $views,
                    $translator,
                    $router
                ),
            [SystemDiagnostics::class, RequestSubject::class, AdminSession::class, CsrfTokens::class,
                Connection::class, TemplateRenderer::class, MessageTranslator::class, Router::class],
        );
        $services->factory(
            PrivateFileHandler::class,
            static fn(RequestSubject $subjects, PrivateImportFiles $files, ErrorResponder $errors): PrivateFileHandler
                => new PrivateFileHandler($subjects, $files, $errors),
            [RequestSubject::class, PrivateImportFiles::class, ErrorResponder::class],
        );
        $services->factory(
            UpdatesHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                Connection $connection,
                Router $router,
                AdminSession $admin,
                RequestSubject $subjects,
                CsrfTokens $csrf,
                string $root,
                string $storage,
                ?Config $config,
                CacheFactory $caches,
                LoggerInterface $logger,
            ): UpdatesHandler => new UpdatesHandler(
                $translator,
                $views,
                new DbalUpdateOverview($connection),
                $router,
                $admin,
                $subjects,
                $csrf,
                new RecoveryPreparation($root, $storage),
                new UpdatePreparation(
                    $connection,
                    $root,
                    $storage,
                    $config?->databaseDriver ?? throw new RuntimeException('update_unavailable'),
                    new GitHubReleaseDiscovery(
                        new NativeReleaseHttpClient(),
                        $caches->namespace('update-discovery'),
                        $logger
                    ),
                    new PackageAssetDownloader(),
                ),
                new DbalAdminAudit($connection),
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                Connection::class,
                Router::class,
                AdminSession::class,
                RequestSubject::class,
                CsrfTokens::class,
                CompositionRoot::ROOT,
                CompositionRoot::STORAGE,
                CompositionRoot::CONFIG,
                CacheFactory::class,
                LoggerInterface::class,
            ],
        );
        $services->factory(
            ReauthHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                UserRepository $users,
                PasswordHasher $hasher,
                LoginThrottle $throttle,
                AdminSession $admin,
                Connection $connection,
                CsrfTokens $csrf,
                Router $router,
                SecondFactors $factors,
            ): ReauthHandler => new ReauthHandler(
                $translator,
                $views,
                $subjects,
                $users,
                $hasher,
                $throttle,
                $admin,
                new DbalAdminAudit($connection),
                $csrf,
                $router,
                $factors,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                UserRepository::class,
                PasswordHasher::class,
                LoginThrottle::class,
                AdminSession::class,
                Connection::class,
                CsrfTokens::class,
                Router::class,
                SecondFactors::class,
            ],
        );
        $services->factory(
            ReportPost::class,
            static fn(
                Connection $connection,
                PostRepository $posts,
                ThreadRepository $threads,
                ForumVisibility $visibility,
            ): ReportPost => new ReportPost(
                $posts,
                $threads,
                $visibility,
                new DbalReportWriter($connection, new Outbox($connection)),
            ),
            [Connection::class, PostRepository::class, ThreadRepository::class, ForumVisibility::class],
        );
        $services->factory(
            ReportHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                ReportPost $reports,
                CsrfTokens $csrf,
                Router $router,
                ErrorResponder $errors,
            ): ReportHandler => new ReportHandler($translator, $views, $subjects, $reports, $csrf, $router, $errors),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                ReportPost::class,
                CsrfTokens::class,
                Router::class,
                ErrorResponder::class,
            ],
        );
        $services->factory(
            ModerationQueueHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                Connection $connection,
                ForumVisibility $visibility,
                CsrfTokens $csrf,
                Router $router,
                ErrorResponder $errors,
            ): ModerationQueueHandler => new ModerationQueueHandler(
                $translator,
                $views,
                $subjects,
                new DbalModerationQueue($connection),
                $visibility,
                $csrf,
                $router,
                $errors,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                Connection::class,
                ForumVisibility::class,
                CsrfTokens::class,
                Router::class,
                ErrorResponder::class,
            ],
        );
        $services->factory(
            ReportDecisionHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                Connection $connection,
                ForumVisibility $visibility,
                Router $router,
                ErrorResponder $errors,
            ): ReportDecisionHandler => new ReportDecisionHandler(
                $translator,
                $views,
                $subjects,
                new ResolveReport(new DbalReportDecisions($connection, new Outbox($connection)), $visibility),
                $router,
                $errors,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                Connection::class,
                ForumVisibility::class,
                Router::class,
                ErrorResponder::class,
            ],
        );
        $services->factory(
            SiteSettings::class,
            static fn(Closure $connection, CacheFactory $caches, string $storage): SiteSettings
                => new SiteSettings($connection, $caches->namespace('settings'), $storage . '/settings/boot.json'),
            [new Lazy(Connection::class), CacheFactory::class, CompositionRoot::STORAGE],
        );
        // ADR-034: config/local.php wins; otherwise the administrator's stored value; otherwise the default.
        $services->factory(
            MailSettings::class,
            static function (?Config $config, SiteSettings $site): MailSettings {
                $mail = $config?->mail ?? new MailSettings();

                return new MailSettings(
                    $mail->transport,
                    $mail->fromAddress ?? $site->get('mail.from_address'),
                    $mail->fromName ?? $site->get('mail.from_name'),
                    $mail->smtpHost,
                    $mail->smtpPort,
                    $mail->smtpEncryption,
                    $mail->smtpUser,
                    $mail->smtpPassword,
                );
            },
            [CompositionRoot::CONFIG, SiteSettings::class],
        );
        $services->factory(
            Mailer::class,
            static fn(MailSettings $settings, LoggerInterface $logger): Mailer => new SymfonyMailer(
                TransportFactory::create($settings),
                $settings,
                $logger,
            ),
            [MailSettings::class, LoggerInterface::class],
        );
        $services->factory(
            MailComposer::class,
            static fn(TemplateRenderer $views, MessageTranslator $translator): MailComposer
                => new MailComposer($views, $translator),
            [TemplateRenderer::class, MessageTranslator::class],
        );
        $services->factory(
            RegistrationRules::class,
            static function (?Config $config, SiteSettings $site): RegistrationRules {
                $rules = $config?->registration ?? new RegistrationRules();
                $mode = $site->get('registration.mode');
                $seconds = $site->get('registration.min_seconds');
                $challenge = $site->get('registration.challenge');

                return new RegistrationRules(
                    $rules->mode === RegistrationRules::VERIFY && $mode !== null ? $mode : $rules->mode,
                    $rules->minSeconds === RegistrationRules::DEFAULT_MIN_SECONDS && $seconds !== null
                        ? (int) $seconds : $rules->minSeconds,
                    $rules->networkSeconds,
                    $rules->challenge === RegistrationRules::CHALLENGE_NONE && $challenge !== null
                        ? $challenge : $rules->challenge,
                );
            },
            [CompositionRoot::CONFIG, SiteSettings::class],
        );
        foreach ([MembersHandler::class => false, MembersHandler::class . '.bulk' => true] as $id => $bulk) {
            $services->factory(
                $id,
                static fn(
                    MessageTranslator $translator,
                    TemplateRenderer $views,
                    RequestSubject $subjects,
                    Connection $connection,
                    AdminSession $admin,
                    CsrfTokens $csrf,
                    Router $router,
                ): MembersHandler => new MembersHandler(
                    $translator,
                    $views,
                    $subjects,
                    new DbalMemberList($connection),
                    $admin,
                    $csrf,
                    $router,
                    $bulk,
                ),
                [
                    MessageTranslator::class,
                    TemplateRenderer::class,
                    RequestSubject::class,
                    Connection::class,
                    AdminSession::class,
                    CsrfTokens::class,
                    Router::class,
                ],
            );
        }

        $forumPages = [ForumManagementHandler::class => false, ForumManagementHandler::class . '.action' => true];

        foreach ($forumPages as $id => $act) {
            $services->factory(
                $id,
                static fn(
                    MessageTranslator $translator,
                    TemplateRenderer $views,
                    RequestSubject $subjects,
                    ForumRepository $forums,
                    Connection $connection,
                    AdminSession $admin,
                    CsrfTokens $csrf,
                    Router $router,
                    PermissionCatalog $catalog,
                    PermissionResolver $resolver,
                ): ForumManagementHandler => new ForumManagementHandler(
                    $translator,
                    $views,
                    $subjects,
                    $forums,
                    new ForumAdmin($forums, $connection),
                    $admin,
                    $csrf,
                    $router,
                    $act,
                    new ForumGuestVisibility($connection, new DbalPermissionRuleStore($connection, $catalog)),
                    $resolver,
                ),
                [
                    MessageTranslator::class,
                    TemplateRenderer::class,
                    RequestSubject::class,
                    ForumRepository::class,
                    Connection::class,
                    AdminSession::class,
                    CsrfTokens::class,
                    Router::class,
                    PermissionCatalog::class,
                    PermissionResolver::class,
                ],
            );
        }

        $services->factory(
            PermissionSimulatorHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                PermissionResolver $resolver,
                UserRepository $users,
                UsergroupRepository $groups,
                ForumRepository $forums,
                Router $router,
                PermissionCatalog $catalog,
            ): PermissionSimulatorHandler => new PermissionSimulatorHandler(
                $translator,
                $views,
                $catalog,
                $resolver,
                $users,
                $groups,
                $forums,
                $router,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                PermissionResolver::class,
                UserRepository::class,
                UsergroupRepository::class,
                ForumRepository::class,
                Router::class,
                PermissionCatalog::class,
            ],
        );
        $services->factory(
            AuditLogHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                Connection $connection,
                Router $router,
            ): AuditLogHandler => new AuditLogHandler($translator, $views, new DbalAuditLog($connection), $router),
            [MessageTranslator::class, TemplateRenderer::class, Connection::class, Router::class],
        );
        $services->factory(
            SavedFiltersHandler::class,
            static fn(
                Connection $db,
                RequestSubject $subjects,
                CsrfTokens $csrf,
                Session $session,
                MessageTranslator $translator,
                TemplateRenderer $views,
                Router $router,
                ?Config $config,
            ): SavedFiltersHandler => new SavedFiltersHandler(
                new SavedAdminFilters($db),
                $subjects,
                $csrf,
                $session,
                $translator,
                $views,
                $router,
                new SettingsCatalog($config, $translator, $router),
            ),
            [Connection::class, RequestSubject::class, CsrfTokens::class, Session::class,
                MessageTranslator::class, TemplateRenderer::class, Router::class, CompositionRoot::CONFIG],
        );
        $services->factory(
            GroupAdministration::class,
            static fn(
                Connection $db,
                UsergroupRepository $groups,
                PermissionCatalog $catalog,
            ): GroupAdministration => new GroupAdministration(
                $db,
                $groups,
                new DbalPermissionRuleStore($db, $catalog),
                $catalog,
            ),
            [Connection::class, UsergroupRepository::class, PermissionCatalog::class],
        );
        $services->factory(
            GroupsHandler::class,
            static fn(
                GroupAdministration $groups,
                RequestSubject $subjects,
                AdminSession $admin,
                CsrfTokens $csrf,
                Session $session,
                MessageTranslator $translator,
                TemplateRenderer $views,
                Router $router,
            ): GroupsHandler => new GroupsHandler(
                $groups,
                $subjects,
                $admin,
                $csrf,
                $session,
                $translator,
                $views,
                $router,
            ),
            [GroupAdministration::class, RequestSubject::class, AdminSession::class, CsrfTokens::class,
                Session::class, MessageTranslator::class, TemplateRenderer::class, Router::class],
        );
        $services->factory(
            SiteSettingsHandler::class,
            static fn(
                SiteSettings $settings,
                ?Config $config,
                RequestSubject $subjects,
                AdminSession $admin,
                CsrfTokens $csrf,
                Session $session,
                MessageTranslator $translator,
                TemplateRenderer $views,
                Router $router,
                string $root,
            ): SiteSettingsHandler => new SiteSettingsHandler(
                $settings,
                $config,
                $subjects,
                $admin,
                $csrf,
                $session,
                $translator,
                $views,
                $router,
                TranslatorFactory::supportedLocales($root . '/translations'),
            ),
            [SiteSettings::class, CompositionRoot::CONFIG, RequestSubject::class, AdminSession::class,
                CsrfTokens::class, Session::class, MessageTranslator::class, TemplateRenderer::class, Router::class,
                CompositionRoot::ROOT],
        );
        $services->factory(
            MemberHandler::class,
            static fn(
                UserRepository $users,
                UsergroupRepository $groupRepository,
                GroupAdministration $groups,
                PasswordRecovery $recovery,
                Connection $db,
                RequestSubject $subjects,
                AdminSession $admin,
                CsrfTokens $csrf,
                Session $session,
                MessageTranslator $translator,
                TemplateRenderer $views,
                Router $router,
                ErrorResponder $errors,
                AccountRegistration $registration,
                SecondFactors $factors,
            ): MemberHandler => new MemberHandler(
                $users,
                $groupRepository,
                $groups,
                new MemberIdentity($db),
                $recovery,
                $subjects,
                $admin,
                $csrf,
                $session,
                $translator,
                $views,
                $router,
                $errors,
                $registration,
                $factors,
            ),
            [UserRepository::class, UsergroupRepository::class, GroupAdministration::class, PasswordRecovery::class,
                Connection::class, RequestSubject::class, AdminSession::class, CsrfTokens::class, Session::class,
                MessageTranslator::class, TemplateRenderer::class, Router::class, ErrorResponder::class,
                AccountRegistration::class, SecondFactors::class],
        );
        $services->factory(
            GroupHandler::class,
            static fn(
                GroupAdministration $groups,
                PermissionCatalog $catalog,
                ForumRepository $forums,
                RequestSubject $subjects,
                AdminSession $admin,
                CsrfTokens $csrf,
                Session $session,
                MessageTranslator $translator,
                TemplateRenderer $views,
                Router $router,
                ErrorResponder $errors,
            ): GroupHandler => new GroupHandler(
                $groups,
                $catalog,
                $forums,
                $subjects,
                $admin,
                $csrf,
                $session,
                $translator,
                $views,
                $router,
                $errors,
            ),
            [GroupAdministration::class, PermissionCatalog::class, ForumRepository::class, RequestSubject::class,
                AdminSession::class, CsrfTokens::class, Session::class, MessageTranslator::class,
                TemplateRenderer::class, Router::class, ErrorResponder::class],
        );
        $services->factory(
            ForumModeratorsHandler::class,
            static fn(
                Connection $db,
                ForumRepository $forums,
                UsergroupRepository $groups,
                RequestSubject $subjects,
                AdminSession $admin,
                CsrfTokens $csrf,
                Session $session,
                MessageTranslator $translator,
                TemplateRenderer $views,
                Router $router,
            ): ForumModeratorsHandler => new ForumModeratorsHandler(
                new ForumModeratorAssignments($db),
                $forums,
                $groups,
                $subjects,
                $admin,
                $csrf,
                $session,
                $translator,
                $views,
                $router,
            ),
            [Connection::class, ForumRepository::class, UsergroupRepository::class, RequestSubject::class,
                AdminSession::class, CsrfTokens::class, Session::class, MessageTranslator::class,
                TemplateRenderer::class, Router::class],
        );
        $services->factory(
            SettingsRegistryHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                Router $router,
                ?Config $config,
            ): SettingsRegistryHandler => new SettingsRegistryHandler(
                new SettingsCatalog($config, $translator, $router),
                $translator,
                $views,
                $router,
            ),
            [MessageTranslator::class, TemplateRenderer::class, Router::class, CompositionRoot::CONFIG],
        );
        $services->factory(
            RegistrationSettingsHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RegistrationRules $rules,
                Router $router,
            ): RegistrationSettingsHandler => new RegistrationSettingsHandler($translator, $views, $rules, $router),
            [MessageTranslator::class, TemplateRenderer::class, RegistrationRules::class, Router::class],
        );
        $services->factory(
            AccountRegistration::class,
            static fn(
                Connection $connection,
                UserRepository $users,
                UsergroupRepository $groups,
                PasswordHasher $hasher,
                Mailer $mailer,
                MailComposer $composer,
                Router $router,
                ?Config $config,
                RegistrationRules $rules,
            ): AccountRegistration => new AccountRegistration(
                $connection,
                $users,
                $groups,
                $hasher,
                new DbalVerificationTokens($connection),
                new TemplatedAccountMail($mailer, $composer, $router, $config?->appUrl),
                new RateBuckets($connection),
                new DbalBanList($connection, new Outbox($connection)),
                rules: $rules,
            ),
            [
                Connection::class,
                UserRepository::class,
                UsergroupRepository::class,
                PasswordHasher::class,
                Mailer::class,
                MailComposer::class,
                Router::class,
                CompositionRoot::CONFIG,
                RegistrationRules::class,
            ],
        );
        $services->factory(
            PasswordRecovery::class,
            static fn(
                Connection $connection,
                UserRepository $users,
                PasswordHasher $hasher,
                Mailer $mailer,
                MailComposer $composer,
                Router $router,
                ?Config $config,
                MessageTranslator $translator,
            ): PasswordRecovery => new PasswordRecovery(
                $users,
                $hasher,
                new DbalResetTokens($connection),
                new TemplatedAccountMail($mailer, $composer, $router, $config?->appUrl),
                new RateBuckets($connection),
                // Without a configuration (before installation) there is no secret to derive links from: direct mail.
                queue: $config === null ? null : new ResetMailQueue($connection, $config->appSecret),
                locale: $translator->locale(),
            ),
            [
                Connection::class,
                UserRepository::class,
                PasswordHasher::class,
                Mailer::class,
                MailComposer::class,
                Router::class,
                CompositionRoot::CONFIG,
                MessageTranslator::class,
            ],
        );

        $services->factory(
            ConversationsHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                Connection $connection,
                CsrfTokens $csrf,
                Router $router,
                PrivateConversations $conversations,
                PrivateImportFiles $files,
            ): ConversationsHandler => new ConversationsHandler(
                $translator,
                $views,
                $subjects,
                $conversations,
                $csrf,
                $router,
                $files,
            ),
            [MessageTranslator::class, TemplateRenderer::class, RequestSubject::class,
                Connection::class, CsrfTokens::class, Router::class, PrivateConversations::class,
                PrivateImportFiles::class],
        );
        $services->factory(
            PrivateConversations::class,
            static fn(Connection $connection, MemberProfiles $profiles): PrivateConversations
                => new PrivateConversations($connection, new RateBuckets($connection), $profiles),
            [Connection::class, MemberProfiles::class],
        );
        $services->factory(
            SearchHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                ForumVisibility $visibility,
                Connection $connection,
                Router $router,
                ForumRepository $forums,
                UserRepository $users,
            ): SearchHandler => new SearchHandler(
                $translator,
                $views,
                $subjects,
                $visibility,
                new DbalPostSearch($connection),
                new RateBuckets($connection),
                $router,
                $forums,
                $users,
            ),
            [MessageTranslator::class, TemplateRenderer::class, RequestSubject::class,
                ForumVisibility::class, Connection::class, Router::class, ForumRepository::class,
                UserRepository::class],
        );
        $services->factory(
            PrivacyHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                AuthSession $auth,
                AdminSession $admin,
                Connection $connection,
                PasswordHasher $hasher,
                string $storage,
                string $root,
                CsrfTokens $csrf,
                Router $router,
                UserRepository $users,
            ): PrivacyHandler => new PrivacyHandler(
                $translator,
                $views,
                $subjects,
                $auth,
                $admin,
                new AccountPrivacy(
                    $connection,
                    $hasher,
                    new RateBuckets($connection),
                    new PersonalDataExport($connection, $storage, $root . '/public')
                ),
                $csrf,
                $router,
                $users,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                AuthSession::class,
                AdminSession::class,
                Connection::class,
                PasswordHasher::class,
                CompositionRoot::STORAGE,
                CompositionRoot::ROOT,
                CsrfTokens::class,
                Router::class,
                UserRepository::class,
            ],
        );
        $services->factory(
            AccountSettings::class,
            static fn(
                Connection $connection,
                UserRepository $users,
                PasswordHasher $hasher,
                Mailer $mailer,
                MailComposer $composer,
                Router $router,
                ?Config $config,
            ): AccountSettings => new AccountSettings(
                $connection,
                $users,
                $hasher,
                new TemplatedAccountMail($mailer, $composer, $router, $config?->appUrl),
                new RateBuckets($connection),
            ),
            [
                Connection::class,
                UserRepository::class,
                PasswordHasher::class,
                Mailer::class,
                MailComposer::class,
                Router::class,
                CompositionRoot::CONFIG,
            ],
        );

        $accountModes = [
            AccountHandler::PAGE,
            AccountHandler::PASSWORD,
            AccountHandler::EMAIL,
            AccountHandler::CONFIRM,
            AccountHandler::PREFERENCES,
        ];

        foreach ($accountModes as $mode) {
            $services->factory(
                AccountHandler::class . '.' . $mode,
                static fn(
                    MessageTranslator $translator,
                    TemplateRenderer $views,
                    AuthSession $auth,
                    UserRepository $users,
                    AccountSettings $settings,
                    CsrfTokens $csrf,
                    Router $router,
                    Closure $factors,
                    Closure $identityProviders,
                    Connection $connection,
                    CsrfTokens $tokens,
                    string $root,
                ): AccountHandler => new AccountHandler(
                    $translator,
                    $views,
                    $auth,
                    $users,
                    $settings,
                    $csrf,
                    $router,
                    $mode,
                    $factors,
                    static fn(int $userId): array => [
                        'accounts' => ExternalAuthHandler::accounts(
                            $identityProviders(),
                            new ExternalIdentities($connection),
                            $userId,
                            $router,
                            $translator,
                        ),
                        'unlink_action' => $router->generate(CoreRoutes::ACCOUNT_EXTERNAL),
                        'csrf_token' => $tokens->token(),
                    ],
                    TranslatorFactory::supportedLocales($root . '/translations'),
                ),
                [
                    MessageTranslator::class,
                    TemplateRenderer::class,
                    AuthSession::class,
                    UserRepository::class,
                    AccountSettings::class,
                    CsrfTokens::class,
                    Router::class,
                    new Lazy(SecondFactors::class),
                    new Lazy(IdentityProviders::class),
                    Connection::class,
                    CsrfTokens::class,
                    CompositionRoot::ROOT,
                ],
            );
        }

        $resetPages = [PasswordResetHandler::class => false, PasswordResetHandler::class . '.reset' => true];

        foreach ($resetPages as $id => $reset) {
            $services->factory(
                $id,
                static fn(
                    MessageTranslator $translator,
                    TemplateRenderer $views,
                    AuthSession $auth,
                    PasswordRecovery $recovery,
                    CsrfTokens $csrf,
                    Router $router,
                ): PasswordResetHandler => new PasswordResetHandler(
                    $translator,
                    $views,
                    $auth,
                    $recovery,
                    $csrf,
                    $router,
                    $reset,
                ),
                [
                    MessageTranslator::class,
                    TemplateRenderer::class,
                    AuthSession::class,
                    PasswordRecovery::class,
                    CsrfTokens::class,
                    Router::class,
                ],
            );
        }

        $services->factory(
            RegisterHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                AuthSession $auth,
                AccountRegistration $registration,
                CsrfTokens $csrf,
                Router $router,
                Session $session,
                RegistrationRules $rules,
                Closure $identityProviders,
            ): RegisterHandler => new RegisterHandler(
                $translator,
                $views,
                $auth,
                $registration,
                $csrf,
                $router,
                $session,
                $rules,
                match ($rules->challenge) {
                    RegistrationRules::CHALLENGE_QUESTION => new QuestionChallenge($session),
                    RegistrationRules::CHALLENGE_WORK => new WorkChallenge($session, new QuestionChallenge($session)),
                    default => new NoChallenge(),
                },
                external: $rules->mode === RegistrationRules::CLOSED ? null
                    : static fn(): array => ExternalAuthHandler::buttons($identityProviders(), $router, $translator),
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                AuthSession::class,
                AccountRegistration::class,
                CsrfTokens::class,
                Router::class,
                Session::class,
                RegistrationRules::class,
                new Lazy(IdentityProviders::class),
            ],
        );
        $verifyPages = [VerifyEmailHandler::class => false, VerifyEmailHandler::class . '.resend' => true];

        foreach ($verifyPages as $id => $resend) {
            $services->factory(
                $id,
                static fn(
                    MessageTranslator $translator,
                    TemplateRenderer $views,
                    AccountRegistration $registration,
                    CsrfTokens $csrf,
                    Router $router,
                ): VerifyEmailHandler => new VerifyEmailHandler(
                    $translator,
                    $views,
                    $registration,
                    $csrf,
                    $router,
                    $resend,
                ),
                [
                    MessageTranslator::class,
                    TemplateRenderer::class,
                    AccountRegistration::class,
                    CsrfTokens::class,
                    Router::class,
                ],
            );
        }
        $services->factory(
            MailSettingsHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                UserRepository $users,
                MailSettings $settings,
                Mailer $mailer,
                MailComposer $composer,
                AdminSession $admin,
                CsrfTokens $csrf,
                Router $router,
            ): MailSettingsHandler => new MailSettingsHandler(
                $translator,
                $views,
                $subjects,
                $users,
                $settings,
                $mailer,
                $composer,
                $admin,
                $csrf,
                $router,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                UserRepository::class,
                MailSettings::class,
                Mailer::class,
                MailComposer::class,
                AdminSession::class,
                CsrfTokens::class,
                Router::class,
            ],
        );
        $services->factory(
            BanListHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                Connection $connection,
                AdminSession $admin,
                CsrfTokens $csrf,
                Router $router,
            ): BanListHandler => new BanListHandler(
                $translator,
                $views,
                $subjects,
                new ManageBans(new DbalBanList($connection, new Outbox($connection))),
                $admin,
                $csrf,
                $router,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                Connection::class,
                AdminSession::class,
                CsrfTokens::class,
                Router::class,
            ],
        );
        $services->factory(
            UserSanctionsHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                Connection $connection,
                UserRepository $users,
                UsergroupRepository $groups,
                PermissionResolver $resolver,
                CsrfTokens $csrf,
                Router $router,
                ErrorResponder $errors,
            ): UserSanctionsHandler => new UserSanctionsHandler(
                $translator,
                $views,
                $subjects,
                new ManageSanctions(
                    new DbalUserSanctions($connection, new Outbox($connection)),
                    static function (int $userId) use ($users, $groups, $resolver): ?array {
                        $user = $users->findById($userId);

                        return $user === null ? null : [
                            'username' => $user->username,
                            'admin' => $resolver->user($userId, $groups->groupIdsOf($userId))
                                ->allows(ManageSanctions::ADMIN),
                        ];
                    },
                ),
                $csrf,
                $router,
                $errors,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                Connection::class,
                UserRepository::class,
                UsergroupRepository::class,
                PermissionResolver::class,
                CsrfTokens::class,
                Router::class,
                ErrorResponder::class,
            ],
        );
        $services->factory(
            BulkModerationHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                Connection $connection,
                ModerateContent $content,
                ThreadCommands $threads,
                Router $router,
            ): BulkModerationHandler => new BulkModerationHandler(
                $translator,
                $views,
                $subjects,
                new BulkModeration($content, $threads, new DbalAccountApprovals($connection, new Outbox($connection))),
                $router,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                Connection::class,
                ModerateContent::class,
                ThreadCommands::class,
                Router::class,
            ],
        );
        $services->factory(
            CreateThreadHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                CreateThread $threads,
                CsrfTokens $csrf,
                Router $router,
                ErrorResponder $errors,
                Drafts $drafts,
                ThreadTaxonomy $taxonomy,
            ): CreateThreadHandler => new CreateThreadHandler(
                $translator,
                $views,
                $subjects,
                $threads,
                $csrf,
                $router,
                $errors,
                $drafts,
                taxonomy: $taxonomy,
            ),
            [
                MessageTranslator::class,
                TemplateRenderer::class,
                RequestSubject::class,
                CreateThread::class,
                CsrfTokens::class,
                Router::class,
                ErrorResponder::class,
                Drafts::class,
                ThreadTaxonomy::class,
            ],
        );
        $services->factory(
            LogoutHandler::class,
            static fn(
                AuthSession $auth,
                Router $router,
                TemplateRenderer $views,
                CsrfTokens $csrf,
                MessageTranslator $translator,
            ): LogoutHandler => new LogoutHandler($auth, $router, $views, $csrf, $translator),
            [AuthSession::class, Router::class, TemplateRenderer::class, CsrfTokens::class, MessageTranslator::class],
        );
        $services->factory(
            InstallerPage::class,
            static fn(MessageTranslator $translator, TemplateRenderer $views): InstallerPage
                => new InstallerPage($translator, $views),
            [MessageTranslator::class, TemplateRenderer::class],
        );
        $services->factory(
            SetupCode::class,
            static fn(string $storage, Session $session): SetupCode => new SetupCode($storage, $session),
            [CompositionRoot::STORAGE, Session::class],
        );
        $services->factory(
            SchemaInstaller::class,
            static fn(Closure $connection): SchemaInstaller => new SchemaInstaller(
                $connection,
                MigrationRegistry::core(),
                afterComplete: [
                    static fn(Connection $db): array => (new PermissionCatalogSync($db))->sync(
                        PermissionCatalog::core(),
                    ),
                    // 2026-11-16: defaults an update must not change (an administrator may have set them).
                    static fn(Connection $db): mixed => FreshInstallDefaults::apply($db),
                ],
            ),
            [new Lazy(Connection::class)],
        );
        $services->factory(
            InstallerDatabaseHandler::class,
            static fn(
                InstallerPage $page,
                InstallationState $state,
                SetupCode $setupCode,
                string $root,
                string $configDirectory,
                CsrfTokens $csrf,
                Router $router,
            ): InstallerDatabaseHandler => new InstallerDatabaseHandler(
                $page,
                $state,
                $setupCode,
                new DatabaseSetup($configDirectory, $root . '/public'),
                $csrf,
                $router,
            ),
            [
                InstallerPage::class,
                InstallationState::class,
                SetupCode::class,
                CompositionRoot::ROOT,
                CompositionRoot::CONFIG_DIRECTORY,
                CsrfTokens::class,
                Router::class,
            ],
        );
        $services->factory(
            InstallerMigrateHandler::class,
            static fn(
                InstallerPage $page,
                InstallationState $state,
                SetupCode $setupCode,
                Closure $schema,
                CsrfTokens $csrf,
                Router $router,
            ): InstallerMigrateHandler => new InstallerMigrateHandler(
                $page,
                $state,
                $setupCode,
                $schema,
                $csrf,
                $router,
            ),
            [
                InstallerPage::class,
                InstallationState::class,
                SetupCode::class,
                new Lazy(SchemaInstaller::class),
                CsrfTokens::class,
                Router::class,
            ],
        );
        $services->factory(
            InstallerMailHandler::class,
            static fn(
                InstallerPage $page,
                InstallationState $state,
                SetupCode $setupCode,
                string $root,
                string $configDirectory,
                MailComposer $composer,
                LoggerInterface $logger,
                Session $session,
                CsrfTokens $csrf,
                Router $router,
            ): InstallerMailHandler => new InstallerMailHandler(
                $page,
                $state,
                $setupCode,
                new MailSetup($configDirectory, $root . '/public'),
                $composer,
                $logger,
                $session,
                $csrf,
                $router,
            ),
            [
                InstallerPage::class,
                InstallationState::class,
                SetupCode::class,
                CompositionRoot::ROOT,
                CompositionRoot::CONFIG_DIRECTORY,
                MailComposer::class,
                LoggerInterface::class,
                Session::class,
                CsrfTokens::class,
                Router::class,
            ],
        );
        $services->factory(
            FirstAdminProvisioner::class,
            static fn(
                Connection $connection,
                UserRepository $users,
                UsergroupRepository $groups,
                PasswordHasher $hasher,
            ): FirstAdminProvisioner => new FirstAdminProvisioner($connection, $users, $groups, $hasher),
            [Connection::class, UserRepository::class, UsergroupRepository::class, PasswordHasher::class],
        );
        $services->factory(
            InstallerAdminHandler::class,
            static fn(
                InstallerPage $page,
                InstallationState $state,
                SetupCode $setupCode,
                Closure $schema,
                Closure $provisioner,
                CsrfTokens $csrf,
                Router $router,
                string $configDirectory,
                string $root,
                Closure $siteSettings,
            ): InstallerAdminHandler => new InstallerAdminHandler(
                $page,
                $state,
                $setupCode,
                $schema,
                $provisioner,
                $csrf,
                $router,
                $configDirectory,
                new MailSetup($configDirectory, $root . '/public'),
                $siteSettings,
            ),
            [
                InstallerPage::class,
                InstallationState::class,
                SetupCode::class,
                new Lazy(SchemaInstaller::class),
                new Lazy(FirstAdminProvisioner::class),
                CsrfTokens::class,
                Router::class,
                CompositionRoot::CONFIG_DIRECTORY,
                CompositionRoot::ROOT,
                new Lazy(SiteSettings::class),
            ],
        );
        $services->factory(
            InstallerHandler::class,
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                PrerequisiteCheck $prerequisites,
                Router $router,
            ): InstallerHandler => new InstallerHandler($translator, $views, $prerequisites, $router),
            [MessageTranslator::class, TemplateRenderer::class, PrerequisiteCheck::class, Router::class],
        );
        $services->factory(
            MaintenanceGuard::class,
            static fn(string $directory, ErrorResponder $errors): MaintenanceGuard
                => new MaintenanceGuard($directory, $errors),
            [CompositionRoot::CONFIG_DIRECTORY, ErrorResponder::class],
        );
        $services->factory(
            InstallationGuard::class,
            static fn(InstallationState $state, Router $router): InstallationGuard
                => new InstallationGuard($state, $router),
            [InstallationState::class, Router::class],
        );
        $services->factory(
            HttpsGuard::class,
            static fn(?Config $config, SiteUrl $site): HttpsGuard
                => new HttpsGuard($config?->requireHttps ?? false, $site),
            [CompositionRoot::CONFIG, SiteUrl::class],
        );
        $services->factory(
            CsrfGuard::class,
            static fn(CsrfTokens $tokens, ErrorResponder $errors): CsrfGuard => new CsrfGuard($tokens, $errors),
            [CsrfTokens::class, ErrorResponder::class],
        );
        $services->factory(
            CommunityActivity::class,
            static fn(Closure $profiles, Closure $subjects, CacheFactory $caches): CommunityActivity
                => new CommunityActivity($profiles, $subjects, $caches->namespace('community-presence')),
            [new Lazy(MemberProfiles::class), new Lazy(RequestSubject::class), CacheFactory::class],
        );
        $services->factory(
            CommunityAccess::class,
            static fn(Connection $db, ForumVisibility $visibility): CommunityAccess
                => new CommunityAccess($db, $visibility),
            [Connection::class, ForumVisibility::class],
        );
        $services->factory(
            MemberProfiles::class,
            static fn(Connection $db, CommunityAccess $access): MemberProfiles => new MemberProfiles($db, $access),
            [Connection::class, CommunityAccess::class],
        );
        $services->factory(
            CommunityInteractions::class,
            static fn(Connection $db, CommunityAccess $access, MemberProfiles $profiles): CommunityInteractions
                => new CommunityInteractions($db, $access, $profiles),
            [Connection::class, CommunityAccess::class, MemberProfiles::class],
        );
        $services->factory(
            CommunityPolls::class,
            static fn(Connection $db, CommunityAccess $access): CommunityPolls => new CommunityPolls($db, $access),
            [Connection::class, CommunityAccess::class],
        );
        $services->factory(
            CommunityNotifications::class,
            static fn(Connection $db, CommunityAccess $access, CommunityInteractions $actions): CommunityNotifications
                => new CommunityNotifications($db, $access, $actions),
            [Connection::class, CommunityAccess::class, CommunityInteractions::class],
        );
        $services->factory(
            CommunityRead::class,
            static fn(Connection $db, CommunityAccess $access, MemberProfiles $profiles): CommunityRead
                => new CommunityRead($db, $access, $profiles),
            [Connection::class, CommunityAccess::class, MemberProfiles::class],
        );
        $services->factory(
            PrivateMedia::class,
            static fn(Connection $db, CommunityAccess $access, MemberProfiles $profiles, string $storage,
                string $root): PrivateMedia => new PrivateMedia(
                    $db,
                    $access,
                    $profiles,
                    new ImageProcessor(),
                    $storage,
                    $root . '/public',
                ),
            [Connection::class, CommunityAccess::class, MemberProfiles::class,
                CompositionRoot::STORAGE, CompositionRoot::ROOT],
        );
        $services->factory(
            CommunityActions::class,
            static fn(MemberProfiles $profiles, CommunityInteractions $interactions, CommunityPolls $polls,
                CommunityNotifications $notifications, PrivateMedia $media, CommunityAccess $access,
                Connection $db): CommunityActions => new CommunityActions(
                    $profiles,
                    $interactions,
                    $polls,
                    $notifications,
                    $media,
                    $access,
                    new RateBuckets($db),
                ),
            [MemberProfiles::class, CommunityInteractions::class, CommunityPolls::class,
                CommunityNotifications::class, PrivateMedia::class, CommunityAccess::class, Connection::class],
        );
        $services->factory(
            CommunityHandler::class,
            static fn(MessageTranslator $translator, TemplateRenderer $views, RequestSubject $subjects,
                MemberProfiles $profiles, CommunityRead $read, CommunityInteractions $interactions,
                CommunityNotifications $notifications, CommunityActions $actions, PrivateMedia $media,
                CsrfTokens $csrf, Router $urls, ErrorResponder $errors, CommunityActivity $activity,
                UserRepository $users, PostRepository $posts, ForumVisibility $visibility,
                MemberStanding $standing): CommunityHandler
                => new CommunityHandler(
                    $translator,
                    $views,
                    $subjects,
                    $profiles,
                    $read,
                    $interactions,
                    $notifications,
                    $actions,
                    $media,
                    $csrf,
                    $urls,
                    $errors,
                    $activity,
                    $users,
                    $posts,
                    $visibility,
                    $standing,
                ),
            [MessageTranslator::class, TemplateRenderer::class, RequestSubject::class, MemberProfiles::class,
                CommunityRead::class, CommunityInteractions::class, CommunityNotifications::class,
                CommunityActions::class, PrivateMedia::class, CsrfTokens::class, Router::class, ErrorResponder::class,
                CommunityActivity::class, UserRepository::class, PostRepository::class, ForumVisibility::class,
                MemberStanding::class],
        );
        $services->factory(
            TaskRuntime::class,
            static fn(
                Connection $db,
                ?Config $config,
                string $root,
                string $storage,
                ExtensionRegistry $extensions,
            ): TaskRuntime
                => new TaskRuntime(
                    $db,
                    $config ?? throw new RuntimeException('tasks_unavailable'),
                    $root,
                    $storage,
                    $extensions
                ),
            [Connection::class, CompositionRoot::CONFIG, CompositionRoot::ROOT, CompositionRoot::STORAGE,
                ExtensionRegistry::class],
        );
        $services->factory(
            TaskAdminHandler::class,
            static fn(Connection $db, TaskRuntime $runtime, string $storage, RequestSubject $subjects,
                AdminSession $admin, CsrfTokens $csrf, TemplateRenderer $views, MessageTranslator $translator,
                Router $urls): TaskAdminHandler => new TaskAdminHandler(
                    new TaskAdministration($db),
                    $runtime,
                    new TaskConsent($storage),
                    new RedisSettingsStore($storage),
                    $subjects,
                    $admin,
                    $csrf,
                    new DbalAdminAudit($db),
                    $views,
                    $translator,
                    $urls,
                    $storage,
                ),
            [Connection::class, TaskRuntime::class, CompositionRoot::STORAGE, RequestSubject::class,
                AdminSession::class, CsrfTokens::class, TemplateRenderer::class, MessageTranslator::class,
                Router::class],
        );
        $services->factory(
            Kernel::class,
            static fn(
                Router $router,
                Closure $installer,
                Closure $installerDatabase,
                Closure $installerMigrate,
                Closure $installerMail,
                Closure $installerAdmin,
                Closure $login,
                Closure $logout,
                Closure $adminDashboard,
                Closure $adminUpdates,
                Closure $imports,
                Closure $diagnostics,
                Closure $privateFiles,
                Closure $legacy,
                Closure $adminReauth,
                Closure $forumIndex,
                Closure $search,
                Closure $moderationQueue,
                Closure $reportDecision,
                Closure $bulkModeration,
                Closure $userSanctions,
                Closure $banList,
                Closure $mailSettings,
                Closure $register,
                Closure $verifyEmail,
                Closure $verifyResend,
                Closure $registrationSettings,
                Closure $settingsRegistry,
                Closure $savedFilters,
                Closure $forumModerators,
                Closure $groupsAdmin,
                Closure $groupAdmin,
                Closure $memberAdmin,
                Closure $siteAdmin,
                Closure $themes,
                Closure $extensionsAdmin,
                Closure $appearance,
                Closure $passwordForgot,
                Closure $passwordReset,
                Closure $accountPage,
                Closure $privacy,
                Closure $conversations,
                Closure $community,
                Closure $taskAdmin,
                Closure $accountPassword,
                Closure $accountEmail,
                Closure $accountConfirm,
                Closure $accountPreferences,
                Closure $auditLog,
                Closure $forumRead,
                Closure $permissionSimulator,
                Closure $members,
                Closure $membersBulk,
                Closure $forumAdmin,
                Closure $forumAdminAction,
                Closure $forumThreads,
                Closure $postPermalink,
                Closure $postReport,
                Closure $createThread,
                Closure $threadPage,
                Closure $reply,
                Closure $editPost,
                Closure $moderatePost,
                Closure $moderateThread,
                Closure $manageThread,
                ErrorResponder $errors,
                ErrorReporter $reporter,
                InstallationGuard $installation,
                HttpsGuard $https,
                CsrfGuard $csrf,
                AdminGuard $admin,
                NativeSession $session,
                ResponseCookies $cookies,
                LocaleContext $locale,
                MaintenanceGuard $maintenance,
                ThemeRequest $themeRequest,
                ExtensionRegistry $extensions,
                Closure $extensionSubjects,
                ExtensionHandlers $extensionHandlers,
                Closure $activity,
                string $storage,
                string $root,
                Closure $seoHandler,
                SeoMetadata $seo,
                Closure $feedHandler,
                Closure $postHistory,
                Closure $threadPosts,
                Closure $threadLabels,
                Closure $tagPage,
                Closure $taxonomyAdmin,
                Closure $gallery,
                Closure $memberLink,
                Closure $memberSuggest,
                Closure $memberExtras,
                Closure $api,
                Closure $webhooksAdmin,
                Closure $loginVerify,
                SecondFactorSetupGuard $secondFactorGuard,
                Closure $externalStart,
                Closure $externalCallback,
                Closure $externalRegister,
                Closure $externalUnlink,
                Closure $externalLink,
            ): Kernel => new Kernel(
                $router,
                [
                    CoreRoutes::ROBOTS => $seoHandler,
                    CoreRoutes::SITEMAP_INDEX => $seoHandler,
                    CoreRoutes::SITEMAP_FORUMS => $seoHandler,
                    CoreRoutes::SITEMAP_THREADS => $seoHandler,
                    CoreRoutes::FEED_LATEST => $feedHandler,
                    CoreRoutes::INDEXNOW_KEY => $seoHandler,
                    CoreRoutes::FEED_FORUM => $feedHandler,
                    CoreRoutes::POST_HISTORY => $postHistory,
                    CoreRoutes::THREAD_POSTS => $threadPosts,
                    CoreRoutes::THREAD_LABELS => $threadLabels,
                    CoreRoutes::TAG_SHOW => $tagPage,
                    CoreRoutes::ADMIN_TAXONOMY => $taxonomyAdmin,
                    CoreRoutes::GALLERY => $gallery,
                    CoreRoutes::MEMBER_LINK => $memberLink,
                    CoreRoutes::MEMBER_SUGGEST => $memberSuggest,
                    CoreRoutes::ADMIN_MEMBER_EXTRAS => $memberExtras,
                    CoreRoutes::API_FORUMS => $api,
                    CoreRoutes::API_FORUM_THREADS => $api,
                    CoreRoutes::API_THREADS => $api,
                    CoreRoutes::API_THREAD => $api,
                    CoreRoutes::ADMIN_WEBHOOKS => $webhooksAdmin,
                    CoreRoutes::GALLERY_ALBUM_NEW => $gallery,
                    CoreRoutes::GALLERY_ALBUM => $gallery,
                    CoreRoutes::GALLERY_ITEM => $gallery,
                    CoreRoutes::GALLERY_FILE => $gallery,
                    CoreRoutes::GALLERY_PENDING => $gallery,
                    CoreRoutes::GALLERY_ATTACH => $gallery,
                    CoreRoutes::ADMIN_TASKS => $taskAdmin,
                    CoreRoutes::ADMIN_CACHE => $taskAdmin,
                    CoreRoutes::COMMUNITY => $community,
                    CoreRoutes::COMMUNITY_MEDIA => $community,
                    CoreRoutes::HOME => $forumIndex,
                    CoreRoutes::INSTALLER_START => $installer,
                    CoreRoutes::INSTALLER_DATABASE => $installerDatabase,
                    CoreRoutes::INSTALLER_MIGRATE => $installerMigrate,
                    CoreRoutes::INSTALLER_MAIL => $installerMail,
                    CoreRoutes::INSTALLER_ADMIN => $installerAdmin,
                    CoreRoutes::LOGIN => $login,
                    CoreRoutes::LOGOUT => $logout,
                    CoreRoutes::LOGIN_VERIFY => $loginVerify,
                    CoreRoutes::AUTH_EXTERNAL => $externalStart,
                    CoreRoutes::AUTH_EXTERNAL_CALLBACK => $externalCallback,
                    CoreRoutes::AUTH_EXTERNAL_REGISTER => $externalRegister,
                    CoreRoutes::ACCOUNT_EXTERNAL => $externalUnlink,
                    CoreRoutes::AUTH_EXTERNAL_LINK => $externalLink,
                    CoreRoutes::ADMIN_DASHBOARD => $adminDashboard,
                    CoreRoutes::ADMIN_UPDATES => $adminUpdates,
                    CoreRoutes::ADMIN_IMPORTS => $imports,
                    CoreRoutes::ADMIN_DIAGNOSTICS => $diagnostics,
                    CoreRoutes::IMPORT_PRIVATE_FILE => $privateFiles,
                    CoreRoutes::LEGACY_RECORD => $legacy,
                    CoreRoutes::LEGACY_URL => $legacy,
                    CoreRoutes::ADMIN_REAUTH => $adminReauth,
                    CoreRoutes::FORUM_INDEX => $forumIndex,
                    CoreRoutes::SEARCH => $search,
                    CoreRoutes::MODERATION_QUEUE => $moderationQueue,
                    CoreRoutes::MODERATION_REPORT => $reportDecision,
                    CoreRoutes::MODERATION_BULK => $bulkModeration,
                    CoreRoutes::MODERATION_USER => $userSanctions,
                    CoreRoutes::ADMIN_BANS => $banList,
                    CoreRoutes::ADMIN_MAIL => $mailSettings,
                    CoreRoutes::REGISTER => $register,
                    CoreRoutes::VERIFY_EMAIL => $verifyEmail,
                    CoreRoutes::VERIFY_RESEND => $verifyResend,
                    CoreRoutes::ADMIN_REGISTRATION => $registrationSettings,
                    CoreRoutes::ADMIN_SETTINGS => $settingsRegistry,
                    CoreRoutes::ADMIN_SAVED_FILTERS => $savedFilters,
                    CoreRoutes::ADMIN_FORUM_MODERATORS => $forumModerators,
                    CoreRoutes::ADMIN_GROUPS => $groupsAdmin,
                    CoreRoutes::ADMIN_GROUP => $groupAdmin,
                    CoreRoutes::ADMIN_MEMBER => $memberAdmin,
                    CoreRoutes::ADMIN_SITE => $siteAdmin,
                    CoreRoutes::ADMIN_THEMES => $themes,
                    CoreRoutes::ADMIN_EXTENSIONS => $extensionsAdmin,
                    CoreRoutes::APPEARANCE => $appearance,
                    CoreRoutes::PASSWORD_FORGOT => $passwordForgot,
                    CoreRoutes::PASSWORD_RESET => $passwordReset,
                    CoreRoutes::ACCOUNT => $accountPage,
                    CoreRoutes::ACCOUNT_PRIVACY => $privacy,
                    CoreRoutes::CONVERSATIONS => $conversations,
                    CoreRoutes::CONVERSATIONS_NEW => $conversations,
                    CoreRoutes::CONVERSATION => $conversations,
                    CoreRoutes::ADMIN_PRIVACY => $privacy,
                    CoreRoutes::ACCOUNT_PASSWORD => $accountPassword,
                    CoreRoutes::ACCOUNT_EMAIL => $accountEmail,
                    CoreRoutes::ACCOUNT_EMAIL_CONFIRM => $accountConfirm,
                    CoreRoutes::ACCOUNT_PREFERENCES => $accountPreferences,
                    CoreRoutes::ADMIN_AUDIT => $auditLog,
                    CoreRoutes::FORUM_READ => $forumRead,
                    CoreRoutes::ADMIN_PERMISSIONS => $permissionSimulator,
                    CoreRoutes::ADMIN_MEMBERS => $members,
                    CoreRoutes::ADMIN_MEMBERS_BULK => $membersBulk,
                    CoreRoutes::ADMIN_FORUMS => $forumAdmin,
                    CoreRoutes::ADMIN_FORUMS_ACTION => $forumAdminAction,
                    CoreRoutes::FORUM_SHOW => $forumThreads,
                    CoreRoutes::POST_SHOW => $postPermalink,
                    CoreRoutes::POST_REPORT => $postReport,
                    CoreRoutes::THREAD_CREATE => $createThread,
                    CoreRoutes::THREAD_SHOW => $threadPage,
                    CoreRoutes::THREAD_PAGE => $threadPage,
                    CoreRoutes::THREAD_ID => $threadPage,
                    CoreRoutes::THREAD_ID_PAGE => $threadPage,
                    CoreRoutes::THREAD_REPLY => $reply,
                    CoreRoutes::POST_EDIT => $editPost,
                    CoreRoutes::POST_MODERATE => $moderatePost,
                    CoreRoutes::THREAD_MODERATE => $moderateThread,
                    CoreRoutes::THREAD_MANAGE => $manageThread,
                ],
                $errors,
                $reporter,
                [$installation, $https, $csrf, $secondFactorGuard, $admin,
                    new ExtensionRouteGuard($extensions, $extensionSubjects, $errors)],
                $session,
                $cookies,
                $locale,
                $maintenance,
                $themeRequest,
                $extensionHandlers,
                static function (Request $request) use ($activity): void {
                    $activity()->record($request);
                },
                is_file($root . '/config/install.lock') ? new SiteAccessLock($storage) : null,
                $seo,
                $extensions->notFound,
            ),
            [
                Router::class,
                new Lazy(InstallerHandler::class),
                new Lazy(InstallerDatabaseHandler::class),
                new Lazy(InstallerMigrateHandler::class),
                new Lazy(InstallerMailHandler::class),
                new Lazy(InstallerAdminHandler::class),
                new Lazy(LoginHandler::class),
                new Lazy(LogoutHandler::class),
                new Lazy(DashboardHandler::class),
                new Lazy(UpdatesHandler::class),
                new Lazy(ImportsHandler::class),
                new Lazy(DiagnosticsHandler::class),
                new Lazy(PrivateFileHandler::class),
                new Lazy(LegacyHandler::class),
                new Lazy(ReauthHandler::class),
                new Lazy(ForumIndexHandler::class),
                new Lazy(SearchHandler::class),
                new Lazy(ModerationQueueHandler::class),
                new Lazy(ReportDecisionHandler::class),
                new Lazy(BulkModerationHandler::class),
                new Lazy(UserSanctionsHandler::class),
                new Lazy(BanListHandler::class),
                new Lazy(MailSettingsHandler::class),
                new Lazy(RegisterHandler::class),
                new Lazy(VerifyEmailHandler::class),
                new Lazy(VerifyEmailHandler::class . '.resend'),
                new Lazy(RegistrationSettingsHandler::class),
                new Lazy(SettingsRegistryHandler::class),
                new Lazy(SavedFiltersHandler::class),
                new Lazy(ForumModeratorsHandler::class),
                new Lazy(GroupsHandler::class),
                new Lazy(GroupHandler::class),
                new Lazy(MemberHandler::class),
                new Lazy(SiteSettingsHandler::class),
                new Lazy(ThemesHandler::class),
                new Lazy(ExtensionsHandler::class),
                new Lazy(AppearanceHandler::class),
                new Lazy(PasswordResetHandler::class),
                new Lazy(PasswordResetHandler::class . '.reset'),
                new Lazy(AccountHandler::class . '.' . AccountHandler::PAGE),
                new Lazy(PrivacyHandler::class),
                new Lazy(ConversationsHandler::class),
                new Lazy(CommunityHandler::class),
                new Lazy(TaskAdminHandler::class),
                new Lazy(AccountHandler::class . '.' . AccountHandler::PASSWORD),
                new Lazy(AccountHandler::class . '.' . AccountHandler::EMAIL),
                new Lazy(AccountHandler::class . '.' . AccountHandler::CONFIRM),
                new Lazy(AccountHandler::class . '.' . AccountHandler::PREFERENCES),
                new Lazy(AuditLogHandler::class),
                new Lazy(ForumReadHandler::class),
                new Lazy(PermissionSimulatorHandler::class),
                new Lazy(MembersHandler::class),
                new Lazy(MembersHandler::class . '.bulk'),
                new Lazy(ForumManagementHandler::class),
                new Lazy(ForumManagementHandler::class . '.action'),
                new Lazy(ForumThreadsHandler::class),
                new Lazy(PostPermalinkHandler::class),
                new Lazy(ReportHandler::class),
                new Lazy(CreateThreadHandler::class),
                new Lazy(ThreadPageHandler::class),
                new Lazy(CreateReplyHandler::class),
                new Lazy(EditPostHandler::class),
                new Lazy(ModerationHandler::class . '.post'),
                new Lazy(ModerationHandler::class . '.thread'),
                new Lazy(ThreadCommandHandler::class),
                ErrorResponder::class,
                ErrorReporter::class,
                InstallationGuard::class,
                HttpsGuard::class,
                CsrfGuard::class,
                AdminGuard::class,
                NativeSession::class,
                ResponseCookies::class,
                LocaleContext::class,
                MaintenanceGuard::class,
                ThemeRequest::class,
                ExtensionRegistry::class,
                new Lazy(RequestSubject::class),
                ExtensionHandlers::class,
                new Lazy(CommunityActivity::class),
                CompositionRoot::STORAGE,
                CompositionRoot::ROOT,
                new Lazy(SeoHandler::class),
                SeoMetadata::class,
                new Lazy(FeedHandler::class),
                new Lazy(PostHistoryHandler::class),
                new Lazy(ThreadPostsHandler::class),
                new Lazy(ThreadLabelsHandler::class),
                new Lazy(TagPageHandler::class),
                new Lazy(TaxonomyAdminHandler::class),
                new Lazy(GalleryHandler::class),
                new Lazy(MemberLinkHandler::class),
                new Lazy(MemberSuggestHandler::class),
                new Lazy(MemberExtrasAdminHandler::class),
                new Lazy(ApiHandler::class),
                new Lazy(WebhooksAdminHandler::class),
                new Lazy(LoginVerifyHandler::class),
                SecondFactorSetupGuard::class,
                new Lazy(ExternalAuthHandler::class . '.' . ExternalAuthHandler::START),
                new Lazy(ExternalAuthHandler::class . '.' . ExternalAuthHandler::CALLBACK),
                new Lazy(ExternalAuthHandler::class . '.' . ExternalAuthHandler::REGISTER),
                new Lazy(ExternalAuthHandler::class . '.' . ExternalAuthHandler::UNLINK),
                new Lazy(ExternalAuthHandler::class . '.' . ExternalAuthHandler::LINK),
            ],
        );
    }

    /**
     * The logged-in user's stored locale and time zone. Only a request that carries a session or remember-me
     * cookie can belong to a logged-in user, so anonymous pages never build the session, the repositories or a
     * connection.
     *
     * @param Closure(): AuthSession $auth
     * @param Closure(): UserRepository $users
     *
     * @return array{locale: ?string, time_zone: ?string}|null
     */
    private static function userPreferences(Closure $auth, Closure $users, Request $request): ?array
    {
        if (!$request->cookies->has(NativeSession::COOKIE) && !$request->cookies->has(AuthSession::REMEMBER_COOKIE)) {
            return null;
        }

        $userId = $auth()->userId($request);
        $user = $userId === null ? null : $users()->findById($userId);

        return $user === null ? null : ['locale' => $user->preferredLocale, 'time_zone' => $user->timeZone];
    }
}
