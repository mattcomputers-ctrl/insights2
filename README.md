# Precision Ink Insights

In-house analytics and operations platform for Precision Ink Corporation.
A **read-only companion** to the Mar-Kov CMS system — Insights never writes
to CMS.

## The tools

| Tool | What it does |
|---|---|
| **Order Readiness** | What can ship right now, what ships once a batch finishes (and which ticket), what's short, discontinued items with open orders, drag-and-drop order priority, order notes (kept forever, searchable), and a production plan of items near/below minimum stock. |
| **Margin Watchdog** | Compare margins and packed costs between two date ranges — company → customer → item, with quantity-weighted averages, colour thresholds and Excel export. Executives only by default. |
| **Scheduling** | Need-driven mill schedule: what to make, on which mill, in colour-ladder order, with dry-grind handling. |

Platform features: users/groups with named permissions (anything you can't
use is invisible, not greyed out), per-group module visibility and landing
pages, a background CMS sync engine with an always-visible status bar,
blackout windows for backups, a CMS kill switch, light/dark themes, and a
one-script installer/updater with automatic rollback.

## Requirements

- Ubuntu Server 22.04 or 24.04 LTS, with network access to the CMS SQL
  Server (default `10.10.10.11:1433`) and to GitHub.
- A read-only CMS SQL login (the shared `sds_readonly` login is fine).

Everything else (Apache, MariaDB, PHP, the SQL Server driver) is installed
by the script below.

## Fresh install

On a brand-new Ubuntu Server, as a user who can `sudo`:

```bash
curl -fsSL https://raw.githubusercontent.com/mattcomputers-ctrl/precisionink-insights/main/install.sh | sudo bash
```

The installer asks for an admin username/password and (optionally) the CMS
connection — you can skip CMS and set it later in **Admin → CMS & Sync**.
It then installs packages, creates the database, migrates, configures
Apache, starts the sync worker service, sets up nightly backups
(03:30, 30 days kept) and log rotation, and prints the URL to open.

Unattended install (no prompts):

```bash
sudo INSIGHTS_ADMIN_USER=admin INSIGHTS_ADMIN_PASS='choose-a-long-password' bash install.sh --unattended
```

## Updating an existing server

```bash
sudo insights-update
```

(or `sudo bash /var/www/precision-ink-insights/update.sh` — the old
command still works). The update backs up code + database first, pulls the
latest code, runs migrations and the full test suite — **and rolls itself
back automatically if anything fails**, leaving the previous version
running. `sudo insights-update --check` just reports whether an update
exists. Admins can also run updates and backups from
**Admin → System settings** in the browser.

### First update onto the 2.0 platform (one-time)

The server that pre-dates the installer upgrades in place — users, saved
presets and settings all survive:

```bash
cd /var/www/precision-ink-insights
sudo bash update.sh
```

then run the new installer once so the sync worker service, backup timer
and privileged helper get installed:

```bash
sudo bash /var/www/precision-ink-insights/install.sh
```

After that, `sudo insights-update` is the only command you need.

## Where things live

| What | Where |
|---|---|
| Application | `/var/www/precision-ink-insights` |
| Configuration (DB + CMS credentials) | `config/config.php`, `config/cms.local.php` (both gitignored) |
| App logs | `storage/logs/` (rotated daily, 14 days) |
| Apache logs | `/var/log/apache2/precision-ink-insights-*.log` |
| Sync worker | `insights-sync-worker.service` → `journalctl -u insights-sync-worker` or `storage/logs/sync-worker.log` |
| Nightly DB backups | `/var/backups/insights/` (snapshot tables excluded — the next sync rebuilds them) |
| Privileged helper | `/usr/local/sbin/insights-helper` (the only thing the web app may sudo) |

## Development

No PHP needed on the dev machine — everything runs through Docker:

```bash
# Unit tests (pure engines + the Margin Watchdog regression fixtures)
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/run.php

# Full integration suite: throwaway MariaDB + the real app over HTTP,
# permission crawl as every seeded group, sync engine flows, Order
# Readiness flows (priority, notes, marks, exports)
sh tests/integration/run-local.sh
```

Key docs: [SCHEMA_NOTES.md](SCHEMA_NOTES.md) (CMS data sources and traps),
[docs/CMS_DATA_MAP.md](docs/CMS_DATA_MAP.md) (Order Readiness queries,
verified live), [docs/DECISIONS.md](docs/DECISIONS.md) (why things are the
way they are), [docs/SPEC-order-readiness.md](docs/SPEC-order-readiness.md)
(the build spec). The Margin Watchdog regression fixtures under
`tests/fixtures/margin-watchdog/` are a hard gate: any change that alters
its numbers fails the update.

## Uninstall

```bash
sudo bash /var/www/precision-ink-insights/bin/uninstall          # keeps the database
sudo bash /var/www/precision-ink-insights/bin/uninstall --purge  # deletes everything
```
