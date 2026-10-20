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

];
