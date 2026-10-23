# Changelog

## 0.2.0 - 2026-10-23

- `Porro\Laravel\SiteHealth`: the checks behind the Statamic and Filament connectors' Site health panel. Works without a Porro account (PHP and Laravel support dates, security advisories in `composer.lock`, Laravel updates, the scheduler, debug mode, migrations, failed jobs, backups and health checks) and adds the site's open Porro findings once connected. Layers add their own checks with the `porro.health` tag.
- Without a site key, the scheduler writes a heartbeat (`porro:heartbeat`) so the panel can tell whether cron runs.
- Requires `porro/php-core` 0.2.

## 0.1.7

- Keep the scheduler heartbeat in a file so deploys don't wipe it.
