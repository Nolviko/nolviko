<?php

declare(strict_types=1);

namespace Nolviko\Core\Routing;

/**
 * Routes of the core application. Modules add their own declarations when their handlers exist.
 */
final class CoreRoutes
{
    public const ROBOTS = 'seo.robots';

    public const SITEMAP_INDEX = 'seo.sitemap_index';

    public const SITEMAP_FORUMS = 'seo.sitemap_forums';

    public const SITEMAP_THREADS = 'seo.sitemap_threads';

    public const FEED_LATEST = 'seo.feed_latest';

    public const INDEXNOW_KEY = 'seo.indexnow_key';

    public const FEED_FORUM = 'seo.feed_forum';

    public const LEGACY_RECORD = 'import.legacy_record';

    public const LEGACY_URL = 'import.legacy_url';

    public const ADMIN_IMPORTS = 'admin.imports';

    public const ADMIN_DIAGNOSTICS = 'admin.diagnostics';

    public const IMPORT_PRIVATE_FILE = 'import.private_file';

    public const COMMUNITY = 'community';

    public const COMMUNITY_MEDIA = 'community.media';

    public const ADMIN_TASKS = 'admin.tasks';

    public const ADMIN_CACHE = 'admin.cache';

    public const HOME = 'home';

    public const SEARCH = 'search.posts';

    public const INSTALLER_START = 'installer.start';

    public const INSTALLER_DATABASE = 'installer.database';

    public const INSTALLER_MIGRATE = 'installer.migrate';

    public const INSTALLER_MAIL = 'installer.mail';

    public const INSTALLER_ADMIN = 'installer.admin';

    public const LOGIN = 'auth.login';

    /** Sign in (GET) or link from the account page (POST) with an outside account (2026-11-15). */
    public const AUTH_EXTERNAL = 'auth.external';

    public const AUTH_EXTERNAL_CALLBACK = 'auth.external_callback';

    /** Choose a username for a new account made with an outside account. */
    public const AUTH_EXTERNAL_REGISTER = 'auth.external_register';

    public const ACCOUNT_EXTERNAL = 'account.external';

    /** Confirm an existing account's password to link an outside account while signing in (2026-11-15). */
    public const AUTH_EXTERNAL_LINK = 'auth.external_link';

    /** Second sign-in step after the password (2026-11-15). */
    public const LOGIN_VERIFY = 'auth.login_verify';

    public const LOGOUT = 'auth.logout';

    public const REGISTER = 'auth.register';

    public const VERIFY_EMAIL = 'auth.verify_email';

    public const VERIFY_RESEND = 'auth.verify_resend';

    public const PASSWORD_FORGOT = 'auth.password_forgot';

    public const PASSWORD_RESET = 'auth.password_reset';

    public const CONVERSATIONS = 'conversations.index';

    public const CONVERSATIONS_NEW = 'conversations.new';

    public const CONVERSATION = 'conversations.show';

    public const ACCOUNT = 'account.show';

    public const ACCOUNT_PRIVACY = 'account.privacy';

    public const ADMIN_PRIVACY = 'admin.privacy';

    public const ACCOUNT_PASSWORD = 'account.password';

    public const ACCOUNT_EMAIL = 'account.email';

    /** POST: the member's own language and time zone (2026-11-17). */
    public const ACCOUNT_PREFERENCES = 'account.preferences';

    public const ACCOUNT_EMAIL_CONFIRM = 'account.email_confirm';

    public const ADMIN_DASHBOARD = 'admin.dashboard';

    public const ADMIN_UPDATES = 'admin.updates';

    public const ADMIN_RECOVERY_ENTRY = 'admin.update_recovery_entry';

    public const ADMIN_REAUTH = 'admin.reauth';

    public const ADMIN_BANS = 'admin.bans';

    public const ADMIN_MAIL = 'admin.mail';

    public const ADMIN_REGISTRATION = 'admin.registration';

    public const ADMIN_SETTINGS = 'admin.settings';

    public const ADMIN_THEMES = 'admin.themes';

    public const ADMIN_EXTENSIONS = 'admin.extensions';

    public const APPEARANCE = 'appearance';

    public const ADMIN_SAVED_FILTERS = 'admin.saved_filters';

    public const ADMIN_FORUM_MODERATORS = 'admin.forum_moderators';

    public const ADMIN_GROUPS = 'admin.groups';

    public const ADMIN_GROUP = 'admin.group';

    public const ADMIN_MEMBER = 'admin.member';

    public const ADMIN_SITE = 'admin.site';

    public const ADMIN_AUDIT = 'admin.audit';

    public const ADMIN_PERMISSIONS = 'admin.permissions';

    public const ADMIN_MEMBERS = 'admin.members';

    public const ADMIN_FORUMS = 'admin.forums';

    public const ADMIN_FORUMS_ACTION = 'admin.forums_action';

    public const ADMIN_MEMBERS_BULK = 'admin.members_bulk';

    public const FORUM_INDEX = 'forums.index';

    public const FORUM_SHOW = 'forums.show';

    public const FORUM_READ = 'forums.read';

    public const POST_SHOW = 'posts.show';

    public const POST_REPORT = 'posts.report';

    public const MODERATION_QUEUE = 'moderation.queue';

    public const MODERATION_REPORT = 'moderation.report';

    public const MODERATION_BULK = 'moderation.bulk';

    public const MODERATION_USER = 'moderation.user';

    public const THREAD_CREATE = 'threads.create';

    /** Canonical thread address (owner decision 2026-11-14, ADR-036): `/threads/{slug}-{id}`. */
    public const THREAD_SHOW = 'threads.show';

    public const THREAD_PAGE = 'threads.page';

    /** `/threads/{id}` (old links, redirects after a form): answers 301 with the canonical address. */
    public const THREAD_ID = 'threads.id';

    public const THREAD_ID_PAGE = 'threads.id_page';

    /** Lower-case ASCII words joined by single hyphens, as CreateThread::slug() writes them. */
    public const THREAD_SLUG = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public const THREAD_REPLY = 'threads.reply';

    public const POST_EDIT = 'posts.edit';

    public const POST_HISTORY = 'posts.history';

    public const POST_MODERATE = 'posts.moderate';

    public const THREAD_MODERATE = 'threads.moderate';

    public const THREAD_MANAGE = 'threads.manage';

    public const THREAD_POSTS = 'threads.posts';

    public const THREAD_LABELS = 'threads.labels';

    public const TAG_SHOW = 'tags.show';

    public const GALLERY = 'gallery';

    public const MEMBER_LINK = 'community.member_link';

    public const MEMBER_SUGGEST = 'community.member_suggest';

    public const GALLERY_ALBUM_NEW = 'gallery.album_new';

    public const GALLERY_ALBUM = 'gallery.album';

    public const GALLERY_ITEM = 'gallery.item';

    public const GALLERY_FILE = 'gallery.file';

    public const GALLERY_PENDING = 'gallery.pending';

    /** Composer image upload (2026-11-09): JSON, into the member's post-images album. */
    public const GALLERY_ATTACH = 'gallery.attach';

    public const ADMIN_TAXONOMY = 'admin.taxonomy';

    public const ADMIN_MEMBER_EXTRAS = 'admin.member_extras';

    public const ADMIN_WEBHOOKS = 'admin.webhooks';

    public const API_FORUMS = 'api.forums';

    public const API_FORUM_THREADS = 'api.forum_threads';

    public const API_THREADS = 'api.threads';

    public const API_THREAD = 'api.thread';

    /** Reachable only while not installed (InstallationGuard); always declared, so the route cache is per release. */
    public const INSTALLER = [
        self::INSTALLER_START,
        self::INSTALLER_DATABASE,
        self::INSTALLER_MIGRATE,
        self::INSTALLER_MAIL,
        self::INSTALLER_ADMIN,
    ];

    /**
     * @return list<RouteDefinition>
     */
    public static function definitions(): array
    {
        return [
            new RouteDefinition(self::ROBOTS, '/robots.txt', ['GET']),
            new RouteDefinition(self::SITEMAP_INDEX, '/sitemap.xml', ['GET']),
            new RouteDefinition(self::SITEMAP_FORUMS, '/sitemaps/forums.xml', ['GET']),
            new RouteDefinition(self::FEED_LATEST, '/feed.xml', ['GET']),
            new RouteDefinition(self::INDEXNOW_KEY, '/indexnow.txt', ['GET']),
            new RouteDefinition(self::FEED_FORUM, '/forums/{forum}/feed.xml', ['GET'], ['forum'
                => '[1-9][0-9]{0,17}']),
            new RouteDefinition(
                self::SITEMAP_THREADS,
                '/sitemaps/{generation}/{page}.xml',
                ['GET'],
                ['generation' => '[a-f0-9]{32}', 'page' => '[1-9][0-9]{0,4}']
            ),
            new RouteDefinition(
                self::LEGACY_RECORD,
                '/legacy/{job}/{entity}/{source}',
                ['GET'],
                ['job' => '[a-f0-9]{32}', 'entity' => 'users|forums|threads|posts|attachments',
                'source' => '[1-9][0-9]{0,18}']
            ),
            new RouteDefinition(
                self::LEGACY_URL,
                '/legacy/{job}/{legacy}',
                ['GET'],
                ['job' => '[a-f0-9]{32}',
                'legacy' => '(?:index|showthread|forumdisplay|member|attachment|vi'
                    . 'ewtopic|viewforum|memberlist)\.php|download/file\.php']
            ),
            new RouteDefinition(
                self::ADMIN_IMPORTS,
                '/admin/imports',
                ['GET', 'POST'],
                requiresHttps: true
            ),
            new RouteDefinition(
                self::ADMIN_DIAGNOSTICS,
                '/admin/diagnostics',
                ['GET', 'POST'],
                requiresHttps: true
            ),
            new RouteDefinition(
                self::IMPORT_PRIVATE_FILE,
                '/conversations/files/{file}',
                ['GET'],
                ['file' => '[a-f0-9]{32}'],
                requiresHttps: true
            ),
            new RouteDefinition(self::ADMIN_TASKS, '/admin/tasks', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(self::ADMIN_CACHE, '/admin/cache', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(
                self::COMMUNITY,
                '/community/{section}',
                ['GET', 'POST'],
                ['section' => 'profile|settings|members|online|notifications|bookmarks|thread|attachments'],
                requiresHttps: true,
            ),
            new RouteDefinition(
                self::COMMUNITY_MEDIA,
                '/community/files/{media}',
                ['GET'],
                ['media' => '[a-f0-9]{32}'],
                requiresHttps: true,
            ),
            new RouteDefinition(self::HOME, '/', ['GET']),
            new RouteDefinition(self::SEARCH, '/search', ['GET']),
            new RouteDefinition(self::INSTALLER_START, '/install', ['GET']),
            new RouteDefinition(self::INSTALLER_DATABASE, '/install/database', ['GET', 'POST']),
            new RouteDefinition(self::INSTALLER_MIGRATE, '/install/migrate', ['GET', 'POST']),
            new RouteDefinition(self::INSTALLER_MAIL, '/install/mail', ['GET', 'POST']),
            new RouteDefinition(self::INSTALLER_ADMIN, '/install/admin', ['GET', 'POST']),
            new RouteDefinition(self::LOGIN, '/login', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(self::LOGIN_VERIFY, '/login/verify', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(self::LOGOUT, '/logout', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(self::REGISTER, '/register', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(
                self::AUTH_EXTERNAL,
                '/login/external/{provider}',
                ['GET', 'POST'],
                ['provider' => '[a-z0-9][a-z0-9-]{0,31}'],
                requiresHttps: true,
            ),
            new RouteDefinition(
                self::AUTH_EXTERNAL_CALLBACK,
                '/login/external/{provider}/callback',
                ['GET'],
                ['provider' => '[a-z0-9][a-z0-9-]{0,31}'],
                requiresHttps: true,
            ),
            new RouteDefinition(
                self::AUTH_EXTERNAL_REGISTER,
                '/register/external',
                ['GET', 'POST'],
                requiresHttps: true,
            ),
            new RouteDefinition(self::ACCOUNT_EXTERNAL, '/account/external', ['POST'], requiresHttps: true),
            new RouteDefinition(self::AUTH_EXTERNAL_LINK, '/login/link-account', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(self::VERIFY_EMAIL, '/verify-email', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(
                self::VERIFY_RESEND,
                '/verify-email/resend',
                ['GET', 'POST'],
                requiresHttps: true
            ),
            new RouteDefinition(
                self::PASSWORD_FORGOT,
                '/password/forgot',
                ['GET', 'POST'],
                requiresHttps: true
            ),
            new RouteDefinition(
                self::PASSWORD_RESET,
                '/password/reset',
                ['GET', 'POST'],
                requiresHttps: true
            ),
            new RouteDefinition(self::CONVERSATIONS, '/conversations', ['GET'], requiresHttps: true),
            new RouteDefinition(
                self::CONVERSATIONS_NEW,
                '/conversations/new',
                ['GET', 'POST'],
                requiresHttps: true
            ),
            new RouteDefinition(
                self::CONVERSATION,
                '/conversations/{conversation}',
                ['GET', 'POST'],
                ['conversation' => '[1-9][0-9]{0,17}'],
                requiresHttps: true
            ),
            new RouteDefinition(self::ACCOUNT, '/account', ['GET'], requiresHttps: true),
            new RouteDefinition(
                self::ACCOUNT_PRIVACY,
                '/account/privacy',
                ['GET', 'POST'],
                requiresHttps: true
            ),
            new RouteDefinition(
                self::ADMIN_PRIVACY,
                '/admin/privacy',
                ['GET', 'POST'],
                requiresHttps: true
            ),
            new RouteDefinition(self::ACCOUNT_PASSWORD, '/account/password', ['POST'], requiresHttps: true),
            new RouteDefinition(self::ACCOUNT_EMAIL, '/account/email', ['POST'], requiresHttps: true),
            new RouteDefinition(self::ACCOUNT_PREFERENCES, '/account/preferences', ['POST'], requiresHttps: true),
            new RouteDefinition(
                self::ACCOUNT_EMAIL_CONFIRM,
                '/account/email/confirm',
                ['GET', 'POST'],
                requiresHttps: true,
            ),
            new RouteDefinition(self::ADMIN_DASHBOARD, '/admin', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(
                self::ADMIN_RECOVERY_ENTRY,
                '/update-recovery.php',
                ['GET', 'POST'],
                requiresHttps: true,
            ),
            new RouteDefinition(
                self::ADMIN_UPDATES,
                '/admin/updates',
                ['GET', 'POST'],
                requiresHttps: true
            ),
            new RouteDefinition(self::ADMIN_REAUTH, '/admin/reauth', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(self::ADMIN_BANS, '/admin/bans', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(self::ADMIN_MAIL, '/admin/mail', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(
                self::ADMIN_REGISTRATION,
                '/admin/registration',
                ['GET'],
                requiresHttps: true
            ),
            new RouteDefinition(self::ADMIN_SETTINGS, '/admin/settings', ['GET'], requiresHttps: true),
            new RouteDefinition(self::ADMIN_THEMES, '/admin/themes', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(
                self::ADMIN_EXTENSIONS,
                '/admin/extensions',
                ['GET', 'POST'],
                requiresHttps: true
            ),
            new RouteDefinition(self::APPEARANCE, '/appearance', ['GET', 'POST']),
            new RouteDefinition(
                self::ADMIN_SAVED_FILTERS,
                '/admin/saved-filters',
                ['GET', 'POST'],
                requiresHttps: true,
            ),
            new RouteDefinition(
                self::ADMIN_FORUM_MODERATORS,
                '/admin/forum-moderators',
                ['GET', 'POST'],
                requiresHttps: true,
            ),
            new RouteDefinition(self::ADMIN_GROUPS, '/admin/groups', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(self::ADMIN_SITE, '/admin/site', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(
                self::ADMIN_MEMBER,
                '/admin/members/{user}',
                ['GET', 'POST'],
                ['user' => '[1-9][0-9]{0,17}'],
                requiresHttps: true,
            ),
            new RouteDefinition(
                self::ADMIN_GROUP,
                '/admin/groups/{group}',
                ['GET', 'POST'],
                ['group' => '[1-9][0-9]{0,17}'],
                requiresHttps: true,
            ),
            new RouteDefinition(self::ADMIN_AUDIT, '/admin/audit', ['GET'], requiresHttps: true),
            new RouteDefinition(
                self::ADMIN_PERMISSIONS,
                '/admin/permissions',
                ['GET'],
                requiresHttps: true
            ),
            new RouteDefinition(self::ADMIN_MEMBERS, '/admin/members', ['GET'], requiresHttps: true),
            new RouteDefinition(self::ADMIN_FORUMS, '/admin/forums', ['GET'], requiresHttps: true),
            new RouteDefinition(
                self::ADMIN_FORUMS_ACTION,
                '/admin/forums/action',
                ['POST'],
                requiresHttps: true
            ),
            new RouteDefinition(
                self::ADMIN_MEMBERS_BULK,
                '/admin/members/bulk',
                ['POST'],
                requiresHttps: true
            ),
            new RouteDefinition(self::FORUM_INDEX, '/forums', ['GET']),
            new RouteDefinition(self::MODERATION_QUEUE, '/moderation/queue', ['GET']),
            new RouteDefinition(self::MODERATION_BULK, '/moderation/bulk', ['POST']),
            new RouteDefinition(
                self::MODERATION_REPORT,
                '/moderation/reports/{report}',
                ['POST'],
                ['report' => '[1-9][0-9]{0,17}'],
            ),
            new RouteDefinition(
                self::MODERATION_USER,
                '/moderation/users/{user}',
                ['GET', 'POST'],
                ['user' => '[1-9][0-9]{0,17}'],
            ),
            new RouteDefinition(self::FORUM_SHOW, '/forums/{forum}', ['GET'], ['forum'
                => '[1-9][0-9]{0,17}']),
            new RouteDefinition(
                self::FORUM_READ,
                '/forums/{forum}/read',
                ['POST'],
                ['forum' => '[1-9][0-9]{0,17}'],
            ),
            new RouteDefinition(self::POST_SHOW, '/posts/{post}', ['GET'], ['post' => '[1-9][0-9]{0,17}']),
            new RouteDefinition(
                self::POST_REPORT,
                '/posts/{post}/report',
                ['GET', 'POST'],
                ['post' => '[1-9][0-9]{0,17}'],
            ),
            new RouteDefinition(self::THREAD_SHOW, '/threads/{slug}-{thread}', ['GET'], ['slug' => self::THREAD_SLUG,
                'thread' => '[1-9][0-9]{0,17}']),
            new RouteDefinition(
                self::THREAD_PAGE,
                '/threads/{slug}-{thread}/page-{page}',
                ['GET'],
                ['slug' => self::THREAD_SLUG, 'thread' => '[1-9][0-9]{0,17}', 'page' => '[1-9][0-9]{0,8}'],
            ),
            new RouteDefinition(self::THREAD_ID, '/threads/{thread}', ['GET'], ['thread' => '[1-9][0-9]{0,17}']),
            new RouteDefinition(
                self::THREAD_ID_PAGE,
                '/threads/{thread}/page-{page}',
                ['GET'],
                ['thread' => '[1-9][0-9]{0,17}', 'page' => '[1-9][0-9]{0,8}'],
            ),
            new RouteDefinition(
                self::THREAD_CREATE,
                '/forums/{forum}/new-thread',
                ['GET', 'POST'],
                ['forum' => '[1-9][0-9]{0,17}'],
            ),
            new RouteDefinition(
                self::THREAD_REPLY,
                '/threads/{thread}/reply',
                ['GET', 'POST'],
                ['thread' => '[1-9][0-9]{0,17}'],
            ),
            new RouteDefinition(self::POST_HISTORY, '/posts/{post}/history', ['GET'], ['post'
                => '[1-9][0-9]{0,17}']),
            new RouteDefinition(self::POST_EDIT, '/posts/{post}/edit', ['GET', 'POST'], ['post'
                => '[1-9][0-9]{0,17}']),
            new RouteDefinition(
                self::POST_MODERATE,
                '/posts/{post}/moderate',
                ['GET', 'POST'],
                ['post' => '[1-9][0-9]{0,17}'],
            ),
            new RouteDefinition(
                self::THREAD_MODERATE,
                '/threads/{thread}/moderate',
                ['GET', 'POST'],
                ['thread' => '[1-9][0-9]{0,17}'],
            ),
            new RouteDefinition(
                self::THREAD_MANAGE,
                '/threads/{thread}/manage',
                ['GET', 'POST'],
                ['thread' => '[1-9][0-9]{0,17}'],
            ),
            new RouteDefinition(
                self::THREAD_LABELS,
                '/threads/{thread}/labels',
                ['GET', 'POST'],
                ['thread' => '[1-9][0-9]{0,17}'],
            ),
            new RouteDefinition(self::TAG_SHOW, '/tags/{tag}', ['GET'], ['tag' => '[a-z0-9][a-z0-9-]{0,59}']),
            new RouteDefinition(self::GALLERY, '/gallery', ['GET']),
            new RouteDefinition(self::MEMBER_LINK, '/members', ['GET']),
            new RouteDefinition(self::MEMBER_SUGGEST, '/members/suggest', ['GET']),
            new RouteDefinition(self::GALLERY_ALBUM_NEW, '/gallery/albums/new', ['GET', 'POST']),
            new RouteDefinition(self::GALLERY_ALBUM, '/gallery/albums/{album}', ['GET', 'POST'], ['album'
                => '[1-9][0-9]{0,17}']),
            new RouteDefinition(self::GALLERY_ITEM, '/gallery/images/{item}', ['GET'], ['item' => '[1-9][0-9]{0,17}']),
            new RouteDefinition(self::GALLERY_FILE, '/gallery/images/{item}/{variant}', ['GET'], ['item'
                => '[1-9][0-9]{0,17}', 'variant' => 'full|thumb']),
            new RouteDefinition(self::GALLERY_PENDING, '/gallery/pending', ['GET', 'POST']),
            new RouteDefinition(self::GALLERY_ATTACH, '/gallery/attach', ['POST']),
            new RouteDefinition(self::ADMIN_TAXONOMY, '/admin/taxonomy', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(self::ADMIN_WEBHOOKS, '/admin/webhooks', ['GET', 'POST'], requiresHttps: true),
            new RouteDefinition(self::API_FORUMS, '/api/v1/forums', ['GET']),
            new RouteDefinition(self::API_FORUM_THREADS, '/api/v1/forums/{forum}/threads', ['GET'], ['forum'
                => '[1-9][0-9]{0,17}']),
            new RouteDefinition(self::API_THREADS, '/api/v1/threads', ['GET']),
            new RouteDefinition(self::API_THREAD, '/api/v1/threads/{thread}', ['GET'], ['thread'
                => '[1-9][0-9]{0,17}']),
            new RouteDefinition(
                self::ADMIN_MEMBER_EXTRAS,
                '/admin/profile-fields',
                ['GET', 'POST'],
                requiresHttps: true,
            ),
            new RouteDefinition(
                self::THREAD_POSTS,
                '/threads/{thread}/posts',
                ['GET', 'POST'],
                ['thread' => '[1-9][0-9]{0,17}'],
            ),
        ];
    }
}
