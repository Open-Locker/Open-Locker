<?php

declare(strict_types=1);

return [
    'permissions' => [
        // Allows a user to sign in to the Filament admin panel.
        'panel.access',

        // Allows viewing and managing user records; manager mutations are scoped to non-admin users.
        'users.manage',

        // Allows managing groups, memberships, and group compartment access.
        'groups.manage',

        // Allows granting and revoking direct compartment access for users.
        'compartment.access.manage',

        // Allows operationally opening any compartment and receiving operational status updates.
        'compartment.open',

        // Allows granting and revoking user roles.
        'roles.manage',

        // Allows changing technical locker-bank configuration like Modbus, provisioning, and heartbeat settings.
        'lockerbank.configure',

        // Allows changing legal and system-wide configuration resources.
        'system.configure',
    ],

    'roles' => [
        'user',
        'manager',
        'admin',
    ],

    'role_bindings' => [
        'user' => [],
        'manager' => [
            'panel.access',
            'users.manage',
            'compartment.access.manage',
            'compartment.open',
        ],
        'admin' => '*',
    ],
];
