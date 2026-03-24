<?php

declare(strict_types=1);

return [
    'mobile_scheme' => env('MOBILE_APP_SCHEME', 'open-locker://'),
    'mobile_reset_path' => env('MOBILE_APP_RESET_PATH', 'reset-password'),
];
