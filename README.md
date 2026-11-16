# Nolviko

**A modern, self-hosted forum that runs on ordinary shared hosting.**
Upload a folder, open your site, follow the installer — no Composer, Node, cron, Redis or shell access needed.

> **Status: public beta (0.1.0-beta.2).** Feature-complete for everyday communities and tested on MySQL,
> MariaDB and PostgreSQL. Expect rough edges; keep backups and read the [known limitations](#known-limitations).

---

## Why Nolviko

- **Shared hosting first.** Plain PHP and a database. Works from a subfolder or the document root, within
  `memory_limit=128M` and `max_execution_time=30`. Long jobs (installs, updates, imports) run in small,
  resumable steps.
- **Safe updates from the admin panel.** Signed release packages, a verified backup before every update,
  maintenance mode and journaled steps that resume after an interruption.
- **Secure by default.** Prepared SQL everywhere, CSRF on every change, strict Content Security Policy with nonces,
  escaping by default, Argon2id passwords, rate limits, privacy-friendly IP storage.
- **Yours to shape.** Themes with design tokens, light and dark mode and live preview; extensions with a typed,
  namespaced API.
- **Multilingual.** English and Turkish included, right-to-left layouts supported, every string translatable.

## Features

**Discussions**
- Categories, forums and sub-forums with per-group and per-forum permissions
- Readable thread addresses (`/threads/my-topic-123`), numbered pages, sticky and locked threads
- BBCode editor with preview, quotes and multi-quote, @mentions, image and video embeds (click to load)
- Reactions, polls, question-and-answer forums with accepted answers, thread prefixes and tags
- Bookmarks, unread tracking, notifications, private conversations
- Edit history, member profiles with custom fields, ranks and badges
- Optional media gallery and image uploads in posts (both off by default)
- Search, RSS and Atom feeds

**Moderation and administration**
- Moderation queue, reports, hide/delete/restore, move, split and merge
- Warnings, temporary or permanent bans, IP, range and email bans, every action audited
- Anti-spam without third-party services: honeypot, proof-of-work challenge, link and first-post rules
- Searchable settings, group and permission editor, member management
- Optional background worker and Redis cache for larger sites (never required)

**Platform**
- Search-engine ready: canonical addresses, sitemaps, Open Graph, structured data, IndexNow
- Read-only REST API and signed webhooks
- Importers for SMF 2.1, MyBB 1.8 and phpBB 3.3, with redirects from old addresses
- Privacy tools: data export and account deletion

## Official extensions and themes

These are **not** part of the default install. Upload them as ZIP files under
*Admin → Extensions* or *Admin → Themes*.

| Package | What it does |
|---|---|
| **Two-step verification (TOTP)** | Authenticator-app codes, QR setup, single-use recovery codes, group enforcement |
| **Sign in with Google** | Sign up and sign in with a Google account, set up entirely from the admin panel |
| **Advanced SEO** | Address patterns, title and description templates, per-page settings, sitemap and robots.txt control, redirect manager |
| **Lumen** theme | Light, airy and modern |
| **Kolaj** theme | Bold and colourful |

## Requirements

| | |
|---|---|
| **PHP** | 8.4.27+ (8.4 series) or 8.5.12+ (8.5 series), `memory_limit` 128M or more |
| **PHP extensions** | pdo plus your database driver, intl, mbstring, json, openssl, fileinfo, session, sodium, zip, gd |
| **Database** | MySQL 8.4, MariaDB 10.11+ or PostgreSQL 16–18 |
| **Web server** | Apache with `.htaccess` (`AllowOverride All`) and mod_rewrite |

The installer checks all of these and explains anything missing.

## Installation

1. Download the release ZIP and upload its contents to your hosting account
   (or upload the ZIP and extract it with your hosting file manager).
2. **Recommended:** point your domain's document root at the `public/` folder.
   If your host only serves the uploaded folder, that works too: the included `.htaccess` files serve
   `public/` and keep every private folder closed.
3. Make sure `storage/` and `config/` are writable by the web server.
4. Create an empty database and a database user in your hosting panel.
5. Open your site in a browser. The installer checks the server, connects to the database, creates the tables,
   sets up mail and your administrator account, then locks itself.

The installer writes your settings to `config/local.php`. That file holds your database password and the site's
secret key: it is never served over the web, and you should keep a private copy of it.

## Updating

Use *Admin → Updates*. Nolviko verifies the package signature, checks compatibility, takes and verifies a
backup, switches to maintenance mode, replaces the files, applies database changes and checks that the new version
works before it opens the site again. If a step is interrupted, the next visit continues from where it stopped;
if it fails, the site stays in maintenance with a recovery page.

Every update only adds tables, columns and indexes; it never changes or deletes your data. Keep your own backup
as well, especially on large sites.

## Documentation

- [Administrator guide](docs/admin-guide.md): installation, settings, moderation, updates, backups
- [Operations](docs/operations.md): hosting, performance, optional worker and Redis, troubleshooting
- [Developer guide](docs/developer-guide.md): architecture, extensions, themes, tests

## Development

This folder is the whole product. `vendor/` is committed and contains **production dependencies only**, so a
copy of the folder runs as is. Never install development packages into it.

Local environment with Docker:

```bash
cp .env.example .env    # then set DB_PASSWORD and DB_ROOT_PASSWORD
docker compose up -d --build --wait
```

Open <http://localhost:8080>.

Tools and tests use a separate vendor directory built from the same lock file:

```bash
COMPOSER_VENDOR_DIR=../dev-vendor composer install
php ../dev-vendor/bin/phpcs --standard=phpcs.xml.dist
php ../dev-vendor/bin/phpunit --testsuite Unit,Contract
php ../dev-vendor/bin/phpunit --testsuite Performance
```

Integration tests need a disposable database (`FORUM_TEST_DB_*` environment variables); they refuse to run
against an installed forum. After changing dependencies, refresh the production vendor with
`composer install --no-dev --optimize-autoloader` and commit it.

Code style is PSR-12 with the project rules in `phpcs.xml.dist`. User-visible text always goes through
translation keys (`translations/en` first, then `translations/tr`).

**Stack:** PHP 8.4 · Symfony HttpFoundation, Routing and Translation 7.4 · Doctrine DBAL 4.5 (no ORM) · Twig 3 ·
PHPUnit 12.

## Known limitations

- Beta software: test on a copy of your community before moving a large forum.
- Accessibility has automated checks; a full screen-reader review is still in progress.
- Importers support SMF 2.1, MyBB 1.8 and phpBB 3.3 only.
- Release packages are signed; you configure the trusted publisher key yourself (no key is bundled).

## Security

Please report security problems privately to the maintainers rather than in a public issue. Include the
version, a description and steps to reproduce. You will get an answer before any public disclosure.

## License

Nolviko is released under the [Apache License 2.0](LICENSE). Bundled third-party packages keep their own
licenses; see [NOTICE](NOTICE) and [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
