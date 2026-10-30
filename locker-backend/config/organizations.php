<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Multi-organization mode
    |--------------------------------------------------------------------------
    |
    | Every installation has a default organization that owns all data. This
    | flag only controls organization management and the switcher in the admin
    | panel (ADR-0067). Leave it off for a single-operator installation.
    |
    */

    'multi_organization' => (bool) env('MULTI_ORGANIZATION', false),

];
