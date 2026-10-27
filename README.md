# porro/laravel-connector

Read-only [Porro](https://porro.dev) connector for Laravel 11–13. It sends one signed report about your site to Porro on the scheduler. It never changes the site and never accepts commands: Porro never connects in.

## Install

```bash
composer require porro/laravel-connector
php artisan porro:connect <site key>
```

Copy the site key from the site's page in Porro. `porro:connect` writes `PORRO_KEY` to `.env` and sends the first report.

Reports then go every 15 minutes on Laravel's scheduler, which needs one cron line. If the scheduler isn't already running, `porro:connect` offers to add it to the server's crontab for you, with the right PHP binary and project folder. If it can't, it prints the line to paste into Forge, Ploi or `crontab -e`. Use `--cron` to add it without asking (handy in provisioning scripts) or `--no-cron` to leave the crontab alone. Skipped it, or connected from another machine? Run `php artisan porro:cron` on the server; it needs no key.

On Laravel Cloud and Vapor the platform runs the scheduler for you, so there's no cron line: turn the scheduler on (Cloud) or leave it on (Vapor), and set `PORRO_KEY` as an environment variable.

Add `php artisan porro:report` to your deploy script so Porro hears about deploys straight away.

Using Filament or Statamic? Install [`porro/filament-connector`](https://github.com/Porrodev/filament-connector) or [`porro/statamic-connector`](https://github.com/Porrodev/statamic-connector) instead. They include this package and add Porro to the control panel. If you install this one on its own, `porro:connect` and `porro:status` point you to the right connector.

## Site health, with or without Porro

`Porro\Laravel\SiteHealth` checks the site from the inside, without a Porro account: PHP and Laravel versions past or close to the end of security support ([endoflife.date](https://endoflife.date), with a bundled copy for when it can't be reached), known security advisories in `composer.lock` ([Packagist's advisories API](https://packagist.org/apidoc#list-security-advisories), the one `composer audit` uses; only package names are sent), newer Laravel releases, whether the scheduler runs, debug mode in production, pending migrations, failed jobs, backups and `spatie/laravel-health` checks. Once the site is connected, it adds the site's open findings in Porro (uptime, SSL, the domain, the server's OS and packages) with links into Porro.

The Statamic and Filament connectors show this as a panel in the control panel. Results are cached for five minutes; the public data behind them for between six hours and a day.

When the site isn't connected, the scheduler still writes a heartbeat every `PORRO_INTERVAL` minutes so the panel can tell whether cron runs.

## What leaves a site

- Laravel, PHP and package versions (names and versions from `composer.lock`; the list is only sent when the lock file changes)
- The environment name, and whether debug mode is on (true or false, never the value of any setting)
- Scheduler heartbeat, failed jobs in the last 24 hours, pending migrations
- For failed jobs, up to five groups from the newest 500 in the last day: the job's name, the exception class, where it was thrown (relative to the app, e.g. `app/Jobs/SendInvoice.php:42`), how many, when the last one failed, and the queue and connection. Not the job's data or the exception message. Set `PORRO_FAILED_JOB_MESSAGES=true` to add each message's first line, cut to 160 characters, with email addresses, file paths and runs of six or more digits replaced by `[email]`, `[path]` and `[number]`. Only for the `database` and `database-uuids` failed job drivers
- The database engine and version (e.g. MySQL 8.0.36, MariaDB 10.11.6), whether it's on the same server, and the provider if it's a known managed database. Never its host, name, user or password
- Newest backup date from `spatie/laravel-backup` and check results from `spatie/laravel-health`, if installed

Reading the site's findings back from Porro is a `GET` signed with the same key; it never sends anything about the site.

## What never does

Content, customer or user data, environment values or secrets. Run `php artisan porro:status --json` to see the exact report your site would send.

## Commands

| Command | Does |
|---|---|
| `porro:connect <key>` | Saves the key, sends the first report and sets up cron (`--cron`, `--no-cron`) |
| `porro:cron` | Adds the scheduler to the crontab on its own, no key needed |
| `porro:report` | Sends a report now (`--packages` forces the package list) |
| `porro:status` | Shows what the next report contains (`--json` for the raw report) |

## Config

| Env | Default | |
|---|---|---|
| `PORRO_KEY` | | The site key |
| `PORRO_INTERVAL` | `15` | Minutes between reports |
| `PORRO_ENABLED` | `true` | Turn reporting off, e.g. on staging |
| `PORRO_FAILED_JOB_MESSAGES` | `false` | Send the first line of failed jobs' exception messages, redacted |

## Developing

```bash
composer install
composer test
```

## Security

Please email security@porro.dev rather than opening an issue. We reply within one working day.

## License

MIT
