<?php

return [
    // URL pública (API Gateway) usada nos links do e-mail e no notification_url do MP.
    'public_url' => env('BILLING_PUBLIC_URL', env('APP_URL', 'http://localhost:8084')),

    // mercadopago | fake
    'gateway' => env('PAYMENT_GATEWAY', 'fake'),

    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN', ''),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET', ''),
        'sandbox' => (bool) env('MERCADOPAGO_SANDBOX', true),
        'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
    ],
];
