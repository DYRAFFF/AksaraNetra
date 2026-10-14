<?php

return [
    'name' => env('APP_NAME', 'AksaraNetra'),
    'env' => env('APP_ENV', env('NODE_ENV', 'development')),
    'debug' => (bool) env('APP_DEBUG', true),
    'url' => env('APP_URL', 'http://127.0.0.1:8787'),
    'timezone' => 'UTC',
    'locale' => 'id',
    'fallback_locale' => 'en',
    'faker_locale' => 'id_ID',
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY', 'base64:'.base64_encode(hash('sha256', env('BACKEND_SECRET', 'aksaranetra-development-secret-change-me'), true))),
    'previous_keys' => [],
];
