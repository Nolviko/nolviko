# M09 operations and migration contracts

Operational contract. Milestone acceptance and executable evidence are tracked outside the runtime package.

## Signed updates

Default operation remains notify/manual only. A release catalog hint is never authority to execute code.
The owner installs private `app/config/update-trust.json` with format1, owner/repository,
installed_version (strict SemVer), channel (`stable` or `prerelease`), and keys (key ID to canonical
base64 Ed25519 public key). No actual publisher key or policy file is bundled or generated.
Never place a signing private key on the forum server. Keep this file owner-readable only.
The first release containing deployment locks and a runtime inventory must be installed manually.
Existing M08 installations are deliberately ineligible for automatic preparation.

Publisher builds set FORUM_RELEASE_VERSION explicitly. Ordinary development builds carry a null version
and cannot be staged through the signed updater. `config/release-compatibility.json` describes PHP,
database, extension, package and core API requirements. `config/runtime-files.json` lists only owned
runtime files; it excludes its own hash to avoid a self-reference, then is independently hashed on load.
The complete ZIP hash authenticates both files. No changed installed core file is silently overwritten.

The administrator reauthenticates and uses HTTPS/CSRF to upload the original ZIP, canonical manifest and
signature. Trust, sequence, expiry, channel, newer version, platform, active extensions, themes, schema,
private storage, writable parent directories, disk and absence of active tasks/imports are checked from
server facts. Browser fields cannot assert those checks. Staging is bounded and restartable.
A frozen independent recovery runtime and approved context precede any activation. Backup capture owns
the maintenance latch, drains shared request/task locks and takes an exclusive deployment lock.
The ordinary backup limits still apply: larger sites must use an owner-managed maintenance backup.
No limit is relaxed for the updater. Code promotion is per-file atomic, journaled and repeatable.
Expand migrations and fresh-runtime smoke checks precede installed-sequence/version persistence.
Failure retains maintenance and recovery controls. Manual restore remains available.

## Local source setup

Only a frozen local read-only SQL copy is supported. Freeze attachments too. MyISAM source tables do not
become coherent merely by opening a transaction. Never point at a live old forum. Credentials should have
SELECT only; each source batch additionally starts a read-only transaction and rolls it back.
No source PHP, plugin code, serialized object or SQL dump is executed. Connections use raw bytes and
explicit UTF-8, Windows-1252 or ISO-8859-1 decoding. Ambiguous/damaged encoding requires source review;
no guessed repair or silent replacement occurs. XMLReader is needed for phpBB s9e text. External entities,
DTDs, processing instructions, invalid XML, NUL, oversize rows and excessive XML depth stop the batch.
Unsupported BBCode remains literal under the native escaping renderer.

The owner installs private `app/config/import-sources.json` (format1, sources list, max8).
Each entry contains id (8–64 safe ASCII), engine (`smf`, `mybb`, `phpbb`), driver (`mysql` or `mariadb`; legacy sources are always MySQL-family, while the forum itself may run on PostgreSQL),
host (localhost/loopback), port, database, username, password, prefix, charset, frozen_copy:true,
attachment_roots (folder ID to absolute original directory), and optional user_map.
Consult ImportSources for exact validation. No real file or source credentials are created by M09.
Use a distinct source_id for a distinct source copy. Source setup is not accepted from browser forms.
A single explicitly mapped source account may link to the initiating owner only when normalized email
matches; it never changes that account's password, groups or privileges. All other collisions stop.

Supported default schema families: SMF2.1.x, MyBB1.8.x, phpBB3.3.x. No custom plugin schemas or XenForo.
Original attachment naming: SMF id_hash.dat with a40hex hash and source folder ID; MyBB optionalYYYYMM/
post_*.attach; phpBB userID_32hex with optional validated extension. Symlinks, traversal, HTTP downloads,
missing/changed files and originals above32MiB stop safely. Imported originals are download-only octet
streams with private/no-store/nosniff/sandbox headers. No imported executable or image is executed/decoded.

## Mapping and resume

Users, nonprivileged source groups, category/forum hierarchy, threads, posts, per-owner PM archives,
original attachments, polls, choices and attributable ballots are mapped. Native IDs are new; durable
mapping and legacy redirects retain source IDs. Source administrator/moderator groups grant no native
privileges. Imported accounts are pending approval or suspended, with private/no-contact/no-online
profiles and no outgoing mail/outbox effects. Supported legacy credentials upgrade through native
throttled successful login; unsupported credentials require reset/review and remain suspended.

Every imported forum denies visibility to every known group until an operator reviews permissions.
This is deliberate quarantine, not a promise to translate arbitrary old ACL semantics. phpBB global
announcements use a quarantined synthetic forum. Moved-topic cycles and excessive hierarchy depth stop.
Private messages are independent single-owner read-only conversation copies. Recipients/BCC lists are
not exposed and administrators gain no download bypass. Deleted source recipients are reported as skips.
Owned orphan files become a personal archive; unowned orphan files are reported, never made public.
Private archive files participate in own-data export and shared-reference-aware deletion cleanup.

Each request locks the job row, verifies revision/source identity, writes at most25 records within a
cooperative2second budget and commits data, mapping and cursor together. File copying has its own byte/
time bounds; deterministic keys and original hashes make retry safe after rollback or interruption.
Source row digests and target row/file hashes are checked again phase by phase before completion.
Final count parity and permission cache version invalidation precede completed state. New imported
content stays quarantined until the operator opens it. Do not edit target imports while verification runs.

Poll totals retain anonymous/deleted-voter aggregate counts without inventing accounts. Attributable
ballots decrement the aggregate as native votes are inserted, preserving totals. Source result visibility,
closed state, maximum choices and long labels are preserved. Unsupported choice limits are read-only.
Native attachment lists remain bounded with explicit continuation pages. Inline public attachment tags
are rewritten only after original files map; ambiguous filename mappings stop. PM attachment markers may
remain literal while the original files are separately listed in the owner archive.

The table coverage report lists every matching source table, including tables not imported. Mapping notes
show up to100 reasons. Source session/security/cache data, plugin tables, old ACLs, audit logs, source
profile customization beyond signatures, post edit history and source mail state are not copied. Original source backups
must be kept for manual review of these fields; this is not a byte-for-byte old-forum clone.

## Legacy URLs and support

`/legacy/{job}/{entity}/{source}` accepts fixed entity types and IDs. The parallel endpoint
`/legacy/{job}/index.php?...` (SMF), showthread/forumdisplay/member/attachment.php (MyBB), and
viewtopic/viewforum/memberlist/download/file.php (phpBB) interprets only that platform's fixed grammar.
An operator can rewrite old paths to these routes while preserving the query string. No rewrite is
installed automatically and no external destination supplied by a request is honored. Lookup requires a
completed job and current native profile/forum/thread/post/file visibility; hidden/deleted data yields404.
Redirects are301 but private/no-store to prevent a shared cache retaining formerly accessible targets.

System diagnostics and explicit fresh-admin POST support export use an allowlist only: PHP/DB versions,
standard extension availability, storage readiness and capped status counts. No environment/config dump,
credentials, paths, logs, source rows, user content or queue payloads are included. Support JSON is64KiB max.

## Primary source provenance

Reduced test fixtures use official column/algorithm contracts, not copied application implementations:
- https://raw.githubusercontent.com/SimpleMachines/SMF/v2.1.7/other/install_2-1_mysql.sql
- https://raw.githubusercontent.com/mybb/mybb/mybb_1840/install/resources/mysql_db_tables.php
- https://raw.githubusercontent.com/phpbb/phpbb/3.3.x/phpBB/phpbb/db/migration/data/v30x/release_3_0_0.php

No upstream PHP is included or executed. M09 adds no runtime or development package.

## Private storage for signed activation

Signed automatic activation requires owner-configured private storage outside the application folder, on the same filesystem. The frozen controller and swap state must survive replacement of the live application. Default installations using application-local storage retain the manual update path. Preflight rejects this configuration before accepting an automatic update, and recovery preparation applies the same boundary. Never move a private directory into public, source, vendor or template trees to bypass the checks.

The actual M09-to-beta fixture uses the unchanged accepted M09 archive and an external private directory. The first updater-enabled baseline still requires manual installation; a fixture's development baseline version is not a published historical release.

Unpublished development archives such as M09 are not supported unattended activation baselines. Use the manual baseline procedure and keep separately restorable member files and database backups. The local beta fixes a development-era staging/swap-root mismatch; that does not retroactively change the frozen updater inside an older archive.
