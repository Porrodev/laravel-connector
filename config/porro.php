<?php

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

    'failed_jobs' => [
        // Send the first line of each failed job's exception message (cut to
        // 160 characters, emails and long numbers redacted). Off by default:
        // messages can hold customer data. Job and exception names are always sent.
        'messages' => (bool) env('PORRO_FAILED_JOB_MESSAGES', false),
    ],

];
