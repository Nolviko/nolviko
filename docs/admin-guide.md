# Administrator guide

## Install and protect the forum

Upload the complete runtime ZIP to a private staging directory first. Preserve its hidden access-control files. Use a supported PHP version with the installer-required extensions and a dedicated database account on MySQL 8.4, MariaDB 10.11 or newer, or PostgreSQL 16 or newer (with pdo_pgsql); the installer asks for the database type and detects MySQL and MariaDB by itself. Open the browser installer over HTTPS where the host offers it (HTTPS is required only when the board is set to require it), follow its checks, create the first administrator, and verify that installation is locked afterwards. Never publish configuration, storage, database exports or recovery codes.

Keep a separately restorable database and file backup before updates or imports (for PostgreSQL, for example `pg_dump` from the hosting panel). A ZIP of application files alone cannot restore member content. Test restoration on an isolated copy. Shared hosting does not require Composer, Node, Redis, cron or shell access.

## Permissions and moderation

Create the forum hierarchy before granting access. Review guest, registered member and moderator access separately. A Never rule can override grants; verify effective access with the permission tools and an independent guest browser. Hidden forums, pending posts and private conversations must remain inaccessible through search, permalinks and attachments.

Use the report and approval queues for moderation. Review the target forum before bulk moves. Prefer reversible moderation actions and retain audit history. Re-authenticate when requested for sensitive administration.

## Members, files and mail

Configure registration policy and delivery before inviting members. Confirm that verification and password-reset mail reaches a test mailbox. Native image uploads require GD; image uploading remains unavailable without it. Imported original files are private, permission-checked downloads rather than executable or inline source content. Review file limits and available storage.

## Background work and diagnostics

Tasks are durable database records processed in bounded steps. Traffic-triggered or manual work can complete on shared hosting; low traffic can delay delivery. A worker or cron is optional and does not provide a guarantee of exact-time delivery. Inspect failed work before retrying it. Diagnostics downloads deliberately omit credentials, logs and member content; still review a report before sharing it.

## Search engines

Configure the forum's authoritative application URL, including its installation subdirectory when applicable. Canonical links and sitemap origins use this owner-controlled URL. Request Host headers cannot select a sitemap origin. robots.txt is a crawler instruction and does not replace access control.

The sitemap becomes available only after a bounded background build completes. A response indicating temporary unavailability before completion is expected. Pages recheck guest access when served; permission changes invalidate the old generation. Private/member pages are excluded. A sitemap capacity warning requires operator review rather than silently publishing a truncated index. Language preferences do not create translated copies of member content.

Thread addresses carry the title: `/threads/{title}-{id}`, with `/page-{n}` for later pages. Old `/threads/{id}` links, an address with an outdated title (after a title edit) and `page-1` answer with a permanent redirect (301) to the current address, so bookmarks and search results keep working. Address patterns chosen by the administrator are planned for the Advanced SEO plugin.

## Updates and imports

Follow operations.md for signed updates, frozen recovery and source-specific imports. Automatic activation requires external private storage on the same filesystem; default application-local storage uses manual updates. The local beta candidate is not an official published release. A local candidate signature is not a production publisher trust anchor. Do not enable unattended updates using development keys.

Imported forums and accounts begin in quarantine. Review source coverage and count/hash reports, permissions, privacy and attachment ownership before opening imported material. Importing does not grant source administrator roles.

## Images

The PHP GD extension is required (the installer checks it). Gallery albums and images in threads and replies are separate switches in Site settings and both start off. With post images on, the editor shows an "Add image" button; the administrator sets the largest image in a post (1–100 MB, capped by the host's upload limit, which the settings page shows) and how many images one thread or reply may contain (1–50). Images already posted stay visible when either switch is turned off.

