<?php

return [
    'key'     => env('LALAMOVE_API_KEY'),
    'secret'  => env('LALAMOVE_API_SECRET'),
    'sandbox' => env('LALAMOVE_SANDBOX', false),
    'market'  => env('LALAMOVE_MARKET', 'MY'),

    // Set to false only while registering the webhook URL in the Lalamove partner
    // portal, which probes the URL unsigned and expects a plain 200. Turn it back
    // on immediately after: while it is off, every webhook is accepted unverified.
    'webhook_verify' => env('LALAMOVE_WEBHOOK_VERIFY', true),
];
