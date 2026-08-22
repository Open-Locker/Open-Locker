<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | First administrator
    |--------------------------------------------------------------------------
    |
    | Email address of the administrator to create when an instance is first
    | deployed. Naming them here rather than granting admin to whoever registers
    | first keeps the privilege a deployment decision. The account is created
    | without a usable password; the administrator gets in through the normal
    | password reset flow, which means mail has to work on first deployment.
    |
    | Leave empty to skip the bootstrap entirely and use `first-admin:create`.
    |
    */

    'first_admin_email' => env('ADMIN_EMAIL'),

];
