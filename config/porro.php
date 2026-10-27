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

];
