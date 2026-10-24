# Changelog

## Unreleased

- Reports group the last day's failed jobs (`failed_jobs_groups`): up to five groups by job and exception class, with where it was thrown, how many, the last failure, queue and connection, so Porro can say which jobs fail and why. Exception messages stay on the site unless `PORRO_FAILED_JOB_MESSAGES` is on, and then only their first line, cut short and redacted. `porro:status` lists the groups.

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
