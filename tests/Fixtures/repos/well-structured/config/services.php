<?php

return [
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
    ],
    'weather' => [
        'url' => env('WEATHER_URL', 'https://example.test'),
    ],
];
