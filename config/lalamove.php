<?php

return [
    'key'            => env('LALAMOVE_API_KEY'),
    'secret'         => env('LALAMOVE_API_SECRET'),
    'sandbox'        => env('LALAMOVE_SANDBOX', false),
    'market'         => env('LALAMOVE_MARKET', 'MY'),
    'webhook_secret' => env('LALAMOVE_WEBHOOK_SECRET'),
];
