<?php

return [

    // Your site key from the site page in Porro. Set by `php artisan porro:connect`.
    'key' => env('PORRO_KEY'),

    // Only change this if you run Porro somewhere else.
    'url' => env('PORRO_URL', 'https://app.porro.dev'),

    // Minutes between reports. Porro raises "connector silent" after two missed reports.
    'interval' => (int) env('PORRO_INTERVAL', 15),

    // Turn reporting off without uninstalling, e.g. on local machines.
    'enabled' => (bool) env('PORRO_ENABLED', true),

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

        // When an IP is flagged: probes and failed logins per hour, 404s
        // and form posts per 10 minutes. Defaults in Porro\Core\Activity\Recorder.
        'thresholds' => [],

        // Paths this app really serves that look like scanner paths.
        'served' => [],

        // POSTs that are never a form flood: app chatter, not forms.
        'ignore_posts' => ['livewire/*', 'livewire-*/*', 'broadcasting/*', 'telescope/*', 'horizon/*', 'nova-api/*', '_debugbar/*'],
    ],

    'blocklist' => [
        // Turn blocked IPs away with a 403 before the app runs.
        'app' => (bool) env('PORRO_BLOCK_APP', true),

        // Write a deny file for nginx to include (production only).
        'nginx' => (bool) env('PORRO_BLOCK_NGINX', false),
        'nginx_path' => env('PORRO_NGINX_PATH', storage_path('porro/nginx-blocklist.conf')),

        // Block at Cloudflare's edge: a token with Account Filter Lists Edit,
        // Zone WAF Edit and Zone Read. Then php artisan porro:cloudflare connect.
        // Only production syncs. The host defaults to APP_URL's.
        'cloudflare_token' => env('PORRO_CLOUDFLARE_TOKEN'),
        'cloudflare_host' => env('PORRO_CLOUDFLARE_HOST'),

        // Never blocked or flagged, and can't be removed in the panel:
        // ['203.0.113.7', ['cidr' => '198.51.100.0/24', 'note' => 'Office']]
        'safelist' => [],

        // Infrastructure that must always reach the site, on top of
        // Cloudflare, Forge and private ranges.
        'protected' => [],
    ],

    'failed_jobs' => [
        // Send the first line of each failed job's exception message (cut to
        // 160 characters, emails and long numbers redacted). Off by default:
        // messages can hold customer data. Job and exception names are always sent.
        'messages' => (bool) env('PORRO_FAILED_JOB_MESSAGES', false),
    ],

];
