<?php

use Porro\Core\Activity\NginxBlocklist;

return [

    // Your site key from the site page in Porro. Set by `php artisan porro:connect`.
    'key' => env('PORRO_KEY'),

    // Only change this if you run Porro somewhere else.
    'url' => env('PORRO_URL', 'https://app.porro.dev'),

    // Minutes between reports. Porro raises "connector silent" after two missed reports.
    'interval' => (int) env('PORRO_INTERVAL', 15),

    // Left unset, only the production environment reports, so a local or
    // staging copy with the live .env stays quiet. Set true or false to override.
    'enabled' => env('PORRO_ENABLED'),

    // The /porro page, like Horizon's: Site health, Activity and Blocking.
    // Open to everyone locally; elsewhere only to users your "viewPorro"
    // gate allows, so it's closed in production until you define one.
    'page' => [
        // Unset: on, unless the Statamic or Filament connector shows these
        // in their own control panel.
        'enabled' => env('PORRO_PAGE'),
        'path' => env('PORRO_PATH', 'porro'),
        'middleware' => ['web'],
    ],

    'activity' => [
        // Record probes, failed logins, 404 bursts and form floods. Unset:
        // production only, so a local copy's own traffic isn't counted.
        'enabled' => env('PORRO_ACTIVITY'),

        // Where activity, the Cloudflare connection and nginx check-ins are
        // kept: Porro's own porro_cache table unless this names another cache
        // store. Never the app's default store, which deploys clear.
        'store' => env('PORRO_ACTIVITY_STORE'),

        // Each server appends suspicious requests to this file and records
        // them in batches, so a request never writes to the database. Unset:
        // storage/porro/activity.buf. false records every request straight
        // away, as happens anyway when the folder can't be written (Vapor).
        'buffer' => env('PORRO_ACTIVITY_BUFFER'),

        // When an IP is flagged: probes and failed logins per hour, 404s
        // and form posts per 10 minutes. Defaults in Porro\Core\Activity\Recorder.
        'thresholds' => [],

        // Paths this app really serves that look like scanner paths.
        'served' => [],

        // AbuseIPDB's reputation check on the IP details page. Sends the IP to
        // AbuseIPDB, so it only runs when someone clicks. Free keys: 1,000/day.
        'abuseipdb_key' => env('PORRO_ABUSEIPDB_KEY'),

        // POSTs that are never a form flood: app chatter, not forms.
        'ignore_posts' => ['livewire/*', 'livewire-*/*', 'broadcasting/*', 'telescope/*', 'horizon/*', 'nova-api/*', '_debugbar/*'],
    ],

    'blocklist' => [
        // Turn blocked IPs away with a 403 before the app runs.
        'app' => (bool) env('PORRO_BLOCK_APP', true),

        // Write a deny file for nginx to include (production only).
        'nginx' => (bool) env('PORRO_BLOCK_NGINX', false),
        // On zero-downtime deploys (releases/{id}), a path that outlives the release.
        'nginx_path' => env('PORRO_NGINX_PATH') ?: NginxBlocklist::stablePath(storage_path('porro/nginx-blocklist.conf')),

        // Put Cloudflare's real IP lines in the same file, so nginx denies the
        // visitor rather than Cloudflare. Unset: on when the site is behind
        // Cloudflare. Set false if your nginx config already has real_ip_header.
        'nginx_real_ip' => env('PORRO_NGINX_REAL_IP'),

        // Where the setup page looks to confirm nginx includes the file, and
        // the stamp the reload job touches. The defaults suit Forge.
        'nginx_config_dirs' => null,
        'nginx_stamp' => null,

        // Block at Cloudflare's edge: a token with Account Filter Lists Edit,
        // Zone WAF Edit and Zone Read. Then php artisan porro:cloudflare connect.
        // Only production syncs. The host defaults to APP_URL's.
        'cloudflare_token' => env('PORRO_CLOUDFLARE_TOKEN'),
        'cloudflare_host' => env('PORRO_CLOUDFLARE_HOST'),

        // Never blocked or flagged, and can't be removed in the panel:
        // ['203.0.113.7', ['cidr' => '198.51.100.0/24', 'note' => 'Office']]
        'safelist' => [],

        // Infrastructure that must always reach the site, on top of
        // Cloudflare, Forge, search crawlers and private ranges.
        'protected' => [],

        // Protected lists a site can switch off: not on Forge, or happy to
        // block a crawler. Cloudflare and private ranges always stay.
        'protect' => [
            'forge' => (bool) env('PORRO_PROTECT_FORGE', true),
            'forge_health' => (bool) env('PORRO_PROTECT_FORGE', true),
            'crawlers' => (bool) env('PORRO_PROTECT_CRAWLERS', true),
        ],
    ],

    'failed_jobs' => [
        // Send the first line of each failed job's exception message (cut to
        // 160 characters, emails and long numbers redacted). Off by default:
        // messages can hold customer data. Job and exception names are always sent.
        'messages' => (bool) env('PORRO_FAILED_JOB_MESSAGES', false),
    ],

];
