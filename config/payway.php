<?php

// ABA PayWay (sandbox by default). Put the real values in .env:
//   PAYWAY_MERCHANT_ID=...   PAYWAY_API_KEY=...
return [
    'merchant_id' => env('PAYWAY_MERCHANT_ID'),
    'api_key' => env('PAYWAY_API_KEY'),
    'base_url' => rtrim(env('PAYWAY_BASE_URL', 'https://checkout-sandbox.payway.com.kh'), '/'),
    // Public URL PayWay can POST the payment result to (use a tunnel such as ngrok while developing locally).
    'callback_url' => env('PAYWAY_CALLBACK_URL'),
    // Where the customer's browser lands after paying on the PayWay page.
    'frontend_url' => rtrim(env('PAYWAY_FRONTEND_URL', 'http://localhost:5173'), '/'),
    // The sample disables SSL verification because of a local certificate problem; keep true unless you hit that.
    'verify_ssl' => filter_var(env('PAYWAY_VERIFY_SSL', true), FILTER_VALIDATE_BOOLEAN),
    'currency' => 'USD',
    // QR image design returned by generate-qr (qr_image_template)
    'qr_template' => env('PAYWAY_QR_TEMPLATE', 'template4_color'),
];