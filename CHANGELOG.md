# Changelog

## 0.3.6 - 2026-10-29

- Links to porro.dev from the README and the package listing now carry UTM params, so Porro can tell which connector a visit came from.

## 0.3.5 - 2026-10-28

- A suspicious request no longer touches the database. It appends one short line to `storage/porro/activity.buf` on its server, and the lines are recorded in batches: one read and one write per IP and hour, however big the flood. Requires `porro/php-core` 0.3.5.
- The buffer is flushed every minute by the scheduler on every server, now and then after a suspicious request's response (and whenever a server hasn't flushed for a minute), and before the Activity, IP and Site health pages read activity. Flags and the exposed-file alert can lag by up to a minute. Blocking is unchanged.
- Counts are exact again during a flood: sampling only applies when requests are recorded straight away.
- `PORRO_ACTIVITY_BUFFER` moves the file, or `false` turns batching off. Where storage can't be written (Vapor) requests are recorded straight away, as before.

## 0.3.4 - 2026-10-28

- Suspicious activity, the Cloudflare connection and nginx check-ins now live in Porro's own `porro_cache` table, so a deploy that runs `cache:clear` (Statamic's install step does) no longer wipes the Activity page and its 48 hours of history. Run `php artisan migrate`. Until then, the app's cache is used as before. `PORRO_ACTIVITY_STORE` picks another cache store.
- Expired activity rows are cleared hourly by the scheduled task, and state saved in the app's cache by earlier versions is carried across once.
- The IP details page sums up what an IP did over 48 hours, flagged or not:
  - requests per hour, total requests, and how many a block turned away;
  - first and last seen;
  - what its probes were after, e.g. "Exposed services: Docker, MLflow";
  - the responses it got, its hosts and methods;
  - its top paths with their last status, and its user agents.
- The page also shows how many requests came through Cloudflare and how many came straight to the server. When some came straight to the server, it says that blocking at Cloudflare won't stop them.
- Once an IP is flagged, all its requests are counted, within 5 minutes; ordinary visitors still cost nothing. Requires `porro/php-core` 0.3.4, which also bounds what a flood can write: past 300 requests from one IP in an hour, counts are sampled and shown as "about".
- A request with an invalid or untrusted Host header is still recorded. The method recorded is the one actually sent, so a `_method` override can't hide a POST flood.
- Lighter on each request: the store check and the compiled blocklist are read once a request, counters take one write for a new key, and the IP page reads 48 hours in one query. Expired rows are now also cleared now and then without the scheduler.

## 0.3.3 - 2026-10-28

- **Blocking → Set up Cloudflare**: a setup page with three steps that tick themselves off. It lists the token's two permission policies as Cloudflare's dashboard names them (Zone WAF Rules: Edit and Zone: Read on the site's domain, Account Rule Lists: Edit on the account), the `.env` line with a copy button, and a **Connect to Cloudflare** button, so no terminal is needed. It says plainly that `porro_blocklist` is shared by every site on the Cloudflare account.
- The Blocking page's Cloudflare row is now one line linking to that page.
- Requires `porro/php-core` 0.3.3: account-owned Cloudflare tokens work, and the probe list covers far more scanner targets with fewer false positives.

## 0.3.2 - 2026-10-28

- On zero-downtime deploys (Forge, Envoyer, Deployer), the nginx deny file and the setup page's example config now use a path that outlives the release: shared storage's real path, or the `current` symlink. A path inside `releases/{id}` went stale after the next deploy, and `nginx -t` failed once that release was cleaned up. If your nginx config includes a `releases/…` path, change it to the one the setup page now shows.
- Requires `porro/php-core` 0.3.1.

## 0.3.1 - 2026-10-28

- The Blocking page's nginx row said setup takes "two one-time steps on each server". It takes three: one for the site and two on each server, as the setup page says.
- The README shows Activity, IP details and Blocking, with screenshots.

## 0.3.0 - 2026-10-27

- Requires `porro/php-core` ^0.3.0, from Packagist.
- **The blocklist and safelist have their own pages** (`/porro/blocking/blocklist` and `/safelist`): search by IP (finds the ranges that cover it), range, reason or who added it; filter by manual, suggested, from config or expiring within 7 days; 50 a page, newest first. The Blocking page shows the newest 10 of each with a link to the rest. `Presenter::entries()` has the data for the other panels.
- **Easier nginx setup:** the setup page has three numbered steps, each ticked off as servers finish it, copy buttons, and an example of the site's config with the include line highlighted. Behind Cloudflare, the deny file now carries Cloudflare's real IP lines itself (`PORRO_NGINX_REAL_IP`, on automatically), so the site's config needs one line and the ranges stay current.
- **Always protected, explained:** on the Blocking page each protected list opens to show its addresses, why it can't be blocked and where it comes from. Google's and Bing's verified crawlers are now protected too. `PORRO_PROTECT_FORGE` and `PORRO_PROTECT_CRAWLERS` switch those lists off; Cloudflare and private ranges always stay. The IP details page says when an IP is protected and offers no block buttons.
- `PORRO_ENABLED` now defaults to off outside `APP_ENV=production`, so a local or staging copy with the live `.env` doesn't report as the live site. An explicit `PORRO_ENABLED=true` still reports from anywhere. `porro:status` and `porro:report` say why reporting is off.
- **IP details** at `/porro/activity/{ip}`, linked from Activity.
  - **Shows:** country, network (Cloudflare Intel, falling back to Radar once Intel is refused), confirmed reverse DNS, and badges for Tor, Spamhaus DROP, hosting, fake crawlers and research scanners.
  - **Also:** the last 48 hours here, block and safelist actions, and an AbuseIPDB check only on click.
  - `Presenter::ip()` has the data for the other panels.
- Recorded activity keeps each IP's last user agent.
- **The activity table is ranked by what's known about each IP:** Spamhaus ×3, Tor ×1.5, Cloudflare risk ×2, and a hosting network trying passwords ×1.5. Research scanners go last, marked "Scanner, low risk". Badges show next to each IP.
- **nginx setup page** at `/porro/blocking/nginx`: the two one-time steps with this site's real paths. The real-IP lines show first when the site is behind Cloudflare.
  - **Every server, worst first:** each one's progress is recorded by `porro:blocklist-sync` every minute. The states are no file yet, include missing, waiting for reload, or loaded.
  - **Missing real-IP lines:** it warns when a server behind Cloudflare doesn't have them.
  - **Paths:** `porro.blocklist.nginx_config_dirs` and `nginx_stamp` change where it looks.
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
