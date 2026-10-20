# porro/laravel-connector

Read-only [Porro](https://porro.dev) connector for Laravel 11–13. It sends one signed report about your site to Porro on the scheduler. It never changes the site and never accepts commands: Porro never connects in.

## Install

```bash
composer require porro/laravel-connector
php artisan porro:connect <site key>
```

Copy the site key from the site's page in Porro. `porro:connect` writes `PORRO_KEY` to `.env` and sends the first report. After that the connector reports every 15 minutes on the scheduler, so make sure `schedule:run` is in cron. Add `php artisan porro:report` to your deploy script so Porro hears about deploys straight away.

Using Filament or Statamic? Install [`porro/filament-connector`](https://github.com/Porrodev/filament-connector) or [`porro/statamic-connector`](https://github.com/Porrodev/statamic-connector) instead. They include this package.

## What leaves a site

- Laravel, PHP and package versions (names and versions from `composer.lock`; the list is only sent when the lock file changes)
- The environment name, and whether debug mode is on (true or false, never the value of any setting)
- Scheduler heartbeat, failed jobs in the last 24 hours, pending migrations
- Newest backup date from `spatie/laravel-backup` and check results from `spatie/laravel-health`, if installed

## What never does

Content, customer or user data, environment values or secrets. Run `php artisan porro:status --json` to see the exact report your site would send.

## Commands

| Command | Does |
|---|---|
| `porro:connect <key>` | Saves the key and sends the first report |
| `porro:report` | Sends a report now (`--packages` forces the package list) |
| `porro:status` | Shows what the next report contains (`--json` for the raw report) |

## Config

| Env | Default | |
|---|---|---|
| `PORRO_KEY` | | The site key |
| `PORRO_INTERVAL` | `15` | Minutes between reports |
| `PORRO_ENABLED` | `true` | Turn reporting off, e.g. on staging |

## Developing

```bash
composer install
composer test
```

## Security

Please email security@porro.dev rather than opening an issue. We reply within one working day.

## License

MIT
