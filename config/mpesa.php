<?php

$env = env('MPESA_API_ENV', 'sandbox');

return [
    'consumer_key'    => env('MPESA_CONSUMER_KEY'),
    'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
    'shortcode'       => env('MPESA_BUSINESS_SHORT_CODE', '174379'),
    'passkey'         => env('MPESA_PASSKEY', 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919'),
    'environment'     => $env,
    'callback_url'    => env('MPESA_CALLBACK_URL', ''),

    'base_url' => $env === 'live'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke',

    // Set to false in .env on local/sandbox to allow callbacks from any IP.
    // Must be true in production — only Safaricom IPs (196.201.214.0/24,
    // 196.201.215.0/24) will be accepted.
    'verify_ip' => env('MPESA_VERIFY_IP', $env === 'live'),
];
