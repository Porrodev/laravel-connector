# Changelog

## Unreleased

- **Cloudflare WAF mode:** `PORRO_CLOUDFLARE_TOKEN` and `php artisan porro:cloudflare connect` block IPs at Cloudflare's edge, through the account's `porro_blocklist` list and a rule added next to the zone's own rules.
  - **When it syncs:** after every change (once the response has gone) and on the scheduled task.
  - **Where:** production only, and only to the connected zone.
  - **Status:** the `/porro` Blocking page shows whether it's connected and when it last synced, plus any error.
- **The `/porro` page**, like Horizon, with Site health, Activity and Blocking tabs.
  - **Access:** open in `local`. Elsewhere it's closed unless a `viewPorro` gate allows the user, and guests never get in. A `managePorroBlocklist` gate can narrow who changes the lists.
  - **Statamic and Filament:** it steps aside when their connector is installed (`PORRO_PAGE` overrides).
  - **Assets:** one Blade layout with inline styles, so nothing to publish or build.
- `Porro\Laravel\Activity\Presenter` shapes activity and blocklist data for a page. Statamic and Filament can share it.
- Suspicious activity and blocking, on `porro/php-core` 0.3.
  - **Migration:** run `php artisan migrate` for the `porro_blocklist` and `porro_safelist` tables.
  - **Watching:** global middleware records scanner probes, 404 bursts and form floods (production only unless `PORRO_ACTIVITY=true`). Failed logins are counted on any guard.
  - **Blocking:** blocked IPs get a 403 before the app runs. Behind Cloudflare it uses the real visitor IP and records their country.
  - **Panel:** `SiteHealth` adds an `activity` section and its issues, including a critical one when `/.env` or `/.git` answers 200.
  - **Commands:** `porro:block` blocks or unblocks from the command line. `porro:blocklist-sync` writes the nginx deny file (`PORRO_BLOCK_NGINX=true`) and runs every minute only in that mode. Otherwise housekeeping runs on the existing scheduled task.
- The HTTP client behind the panel moved to `Porro\Laravel\HttpSources`.

## 0.2.2 - 2026-10-25

- Reports group the last day's failed jobs (`failed_jobs_groups`): up to five groups by job and exception class, with where it was thrown, how many, the last failure, queue and connection, so Porro can say which jobs fail and why. Exception messages stay on the site unless `PORRO_FAILED_JOB_MESSAGES` is on, and then only their first line, cut short, with emails, file paths and long numbers redacted. `porro:status` lists the groups.

## 0.2.1

- Requires `porro/php-core` 0.2.1: requests to Porro are signed for their method and path, and the panel only hides local issues Porro has actually raised.
- "Check again" clears the cached Porro findings again (php-core now caches them per Porro URL and key).
- The panel's HTTP client stops reading a response once it's over php-core's size limit.
- Reports describe the default database connection (engine, version, whether it's on the same machine, managed provider) so Porro can flag an end-of-life database. The host, database name, user and credentials are never sent.

## 0.2.0 - 2026-10-23

- `Porro\Laravel\SiteHealth`: the checks behind the Statamic and Filament connectors' Site health panel. Works without a Porro account (PHP and Laravel support dates, security advisories in `composer.lock`, Laravel updates, the scheduler, debug mode, migrations, failed jobs, backups and health checks) and adds the site's open Porro findings once connected. Layers add their own checks with the `porro.health` tag.
- Without a site key, the scheduler writes a heartbeat (`porro:heartbeat`) so the panel can tell whether cron runs.
- Requires `porro/php-core` 0.2.

## 0.1.7

- Keep the scheduler heartbeat in a file so deploys don't wipe it.
