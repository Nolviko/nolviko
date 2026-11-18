# Compatibility

What Nolviko runs on, what was tested, and what is still maintained by its vendor. Running on a branch is not a
claim that the branch still receives security fixes: the "Vendor support" column keeps that apart.
Policy: ADR-001 (amended 2026-11-18 for 0.1.0-beta.10).

## PHP

| Branch | Accepted from | Vendor support (security) | Tested 2026-11-18 | Result |
|---|---|---|---|---|
| 8.2 | 8.2.35 | until 2026-12-31 | 8.2.35 | syntax of all shipped files, production dependency check, boot: pass. PHPUnit 12 needs 8.3, so the suites run in CI from 8.3 |
| 8.3 | 8.3.36 | until 2027-12-31 | 8.3.36 (ICU 72) | Unit 1636, Contract 102, Integration on three databases (below): pass |
| 8.4 | 8.4.27 | until 2028-12-31 | 8.4.27 | full release check, Integration 357: pass |
| 8.5 | 8.5.12 | until 2029-12-31 | CI (compat.yml) | runs on every push |
| 8.6 | — | RC | — | not accepted yet; same tests once stable |

After a branch's security support ends the forum keeps running and the installer warns. Required extensions: pdo with
the database driver (pdo_mysql or pdo_pgsql), intl, mbstring, json, openssl, fileinfo, session, sodium, zip, gd.
Memory limit 128M or more; every request is built to finish within a 30-second limit.

## Databases

| Engine | Accepted | Vendor support | Tested 2026-11-18 (PHP 8.3.36, pdo_mysql/pdo_pgsql) | Result |
|---|---|---|---|---|
| MySQL | 8.0 or newer, below 10 | 8.0 has reached end of life; 8.4 is the LTS | 8.0.46 | Integration 357/357 pass |
| MySQL | | | 8.4 (local stack, PHP 8.4) | Integration 357/357 pass |
| MariaDB | 10.6 or newer | 10.6 LTS until 2026-07; 10.11, 11.4, 11.8 LTS later | 10.6.28 | Integration 357/357 pass |
| MariaDB | | | 10.11, 11.4, 11.8 | CI (compat.yml) |
| PostgreSQL | 14 or newer | 14 until 2026-11; 15–18 later | 14.25 | Integration 357 pass (12 legacy-import tests need a MySQL source and are skipped in this setup) |
| PostgreSQL | | | 15, 16, 17, 18 | CI (compat.yml); 16 also in the local release checks |

The covered behaviour includes a fresh schema, upgrades, indexes, sorting and keyset pagination, backup and restore,
Turkish text and emoji, time zones, transactions, MySQL/MariaDB collations and PostgreSQL sequences (the Integration
suite). No global SQL mode, server setting or administrator account is needed: the forum sets its own session
options on each connection. Vendor support dates change; check the vendors' pages when you install:
https://www.php.net/supported-versions.php, https://dev.mysql.com/doc/refman/8.4/en/mysql-releases.html,
https://mariadb.org/about/#maintenance-policy, https://www.postgresql.org/support/versioning/

## How to re-run

- CI: `.github/workflows/compat.yml` (PHP 8.2–8.5, eleven database branches).
- Locally (workspace): `docker build --build-arg PHP=8.3 -t nolviko-php83 dev/compat` and
  `bash dev/compat/db-matrix.sh nolviko-php83 mysql:mysql:8.0 mariadb:mariadb:10.6 postgresql:postgres:14`.

Official packages: Two-step verification 1.0.5, Sign in with Google 1.1.3 and Advanced SEO 1.0.5 accept PHP 8.2–8.5.
The Lumen and Kolaj themes still need PHP 8.4 until their next release.
