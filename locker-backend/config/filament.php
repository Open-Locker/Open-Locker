<?php

declare(strict_types=1);

return [
    'broadcasting' => [
        'echo' => [
            'broadcaster' => 'reverb',
            'key' => env('VITE_REVERB_APP_KEY'),
            'wsHost' => env('VITE_REVERB_HOST'),
            'wsPort' => env('VITE_REVERB_PORT'),
            'wssPort' => env('VITE_REVERB_PORT'),
            'authEndpoint' => '/broadcasting/auth',
            'disableStats' => true,
            'forceTLS' => env('VITE_REVERB_SCHEME', 'https') === 'https',
            'enabledTransports' => ['ws', 'wss'],
        ],
    ],
];
