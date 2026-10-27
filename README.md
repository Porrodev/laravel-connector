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

Using Filament or Statamic? Install [`porro/filament-connector`](https://github.com/Porrodev/filament-connector) or [`porro/statamic-connector`](https://github.com/Porrodev/statamic-connector) instead. They include this package.

## Site health, with or without Porro

`Porro\Laravel\SiteHealth` checks the site from the inside, without a Porro account: PHP and Laravel versions past or close to the end of security support ([endoflife.date](https://endoflife.date), with a bundled copy for when it can't be reached), known security advisories in `composer.lock` ([Packagist's advisories API](https://packagist.org/apidoc#list-security-advisories), the one `composer audit` uses; only package names are sent), newer Laravel releases, whether the scheduler runs, debug mode in production, pending migrations, failed jobs, backups and `spatie/laravel-health` checks. Once the site is connected, it adds the site's open findings in Porro (uptime, SSL, the domain, the server's OS and packages) with links into Porro.

The Statamic and Filament connectors show this as a panel in the control panel. Results are cached for five minutes; the public data behind them for between six hours and a day.

When the site isn't connected, the scheduler still writes a heartbeat every `PORRO_INTERVAL` minutes so the panel can tell whether cron runs.

## The /porro page

Like Horizon's dashboard, but for the site's health. Visit `/porro` for three tabs:

- **Site health:** the checks above, and Porro's findings once connected
- **Activity:** suspicious requests in the last 24 hours, with buttons to block or safelist each IP
- **Blocking:** the blocklist and safelist, and the ranges that can never be blocked

**Locked down like Horizon.** In the `local` environment anyone can open it. Anywhere else it returns 403 unless your app defines a `viewPorro` gate and the gate allows the signed-in user. Guests never get in. With no gate, production is closed to everyone. Define the gate in a service provider's `boot()`:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewPorro', fn (User $user) => in_array($user->email, ['ops@youragency.com']));
```

Changing the lists also checks a `managePorroBlocklist` gate when you define one. Without it, anyone who can view the page can change the lists.

- **Path:** set `PORRO_PATH` to move it.
- **Middleware:** set `porro.page.middleware` to change it (default `web`).
- **Turning it off:** `PORRO_PAGE=false`.
- **Statamic and Filament sites:** the page turns itself off, because their connectors show the same things in the control panel. Set `PORRO_PAGE=true` to have both.
- **Assets:** everything is in one Blade layout with inline styles, so there's nothing to publish or build.

## Suspicious activity and blocking

Works without a Porro account. IP addresses stay on the site.

- **Run the migration first:** `php artisan migrate` adds the `porro_blocklist` and `porro_safelist` tables.
- **What's watched:** requests for scanner paths like `/.env`, `/.git` and `wp-login.php`, failed sign-ins on any guard, bursts of 404s and floods of form posts.
  - Counts are kept in the cache for 48 hours.
  - Only those requests are recorded, so normal page views cost nothing.
  - Usernames are kept only as hashes.
- **Production only by default.** A local or staging copy records nothing unless `PORRO_ACTIVITY=true`.
- **Exposed files:** if `/.env` or `/.git` ever answers 200, the panel raises it as critical.
- **Blocking:** blocked IPs get a plain 403 before the app runs, from middleware that's first in the global stack.
  - Behind Cloudflare, the visitor's real IP is used and Cloudflare itself is never blocked.
- **Always protected:** Cloudflare, Laravel Forge and its health checks, private ranges, your safelist and `porro.blocklist.protected`. Wide ranges and your own IP are refused too.
- **Country:** behind Cloudflare it comes from the request for free. Otherwise a free [DB-IP](https://db-ip.com) country file is downloaded monthly into `storage/porro`.
- **Cloudflare WAF mode**, for sites behind Cloudflare. Blocked IPs are stopped at Cloudflare's edge, before they reach the server.
  - **Token:** create a Cloudflare API token with **Account Filter Lists Edit**, **Zone WAF Edit** and **Zone Read**, and set `PORRO_CLOUDFLARE_TOKEN`. The token stays in the environment, never the database.
  - **Connect:** run `php artisan porro:cloudflare connect`. It finds the zone serving `APP_URL` (or `PORRO_CLOUDFLARE_HOST`) and creates or reuses the account's `porro_blocklist` IP List. It then adds one custom rule blocking that list **next to your existing rules**, never replacing them.
  - **Syncing:** it syncs after every change and on the scheduled task. Only production syncs, and only to the zone it connected to, so a local or staging copy can't push its list.
  - **Shared list:** the list is shared by every site on the Cloudflare account. Each site only adds and removes items tagged with its own id, and an IP another site already blocked is left alone.
  - **Commands:** `porro:cloudflare` shows the status. `porro:cloudflare sync` syncs now, and `porro:cloudflare disconnect` removes this site's items and the rule.
- **nginx mode** (`PORRO_BLOCK_NGINX=true`):
  - `porro:blocklist-sync` writes a `deny` file every minute on each server, in production only.
  - Include it in the site's nginx config and reload nginx when it changes.
  - Without nginx mode, there's no extra scheduled task, so hosts that scale to zero can still sleep.

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
| `porro:block <ip>` | Blocks an IP or range (`--reason`, `--days`; `--remove` unblocks) |
| `porro:blocklist-sync` | Tidies the blocklist and writes the nginx deny file |
| `porro:cloudflare [status\|connect\|sync\|disconnect]` | Blocks at Cloudflare's edge |

## Config

| Env | Default | |
|---|---|---|
| `PORRO_KEY` | | The site key |
| `PORRO_INTERVAL` | `15` | Minutes between reports |
| `PORRO_ENABLED` | `true` | Turn reporting off, e.g. on staging |
| `PORRO_FAILED_JOB_MESSAGES` | `false` | Send the first line of failed jobs' exception messages, redacted |
| `PORRO_ACTIVITY` | unset | Record suspicious activity; unset means production only |
| `PORRO_BLOCK_APP` | `true` | Turn blocked IPs away with a 403 |
| `PORRO_BLOCK_NGINX` | `false` | Write the nginx deny file every minute |
| `PORRO_NGINX_PATH` | `storage/porro/nginx-blocklist.conf` | Where the deny file goes |
| `PORRO_CLOUDFLARE_TOKEN` | | Cloudflare API token for WAF mode |
| `PORRO_CLOUDFLARE_HOST` | `APP_URL`'s host | The host whose Cloudflare zone gets the rule |
| `PORRO_PAGE` | unset | The /porro page: unset means on, except alongside the Statamic or Filament connector |
| `PORRO_PATH` | `porro` | Where the page lives |

The safelist, extra protected ranges, thresholds and the paths your app really serves go in `config/porro.php` under `activity` and `blocklist`.

## Developing

```bash
composer install
composer test
```

## Security

Please email security@porro.dev rather than opening an issue. We reply within one working day.

## License

MIT
