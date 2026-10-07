<?php

declare(strict_types=1);

return [
    /*
     * Whether this installation hosts more than one operator.
     *
     * This gates UI and workflows only — organization creation, membership
     * management, the switcher, platform administration. It never changes the
     * data model or the authorization path: ownership is always present and a
     * single-organization installation simply holds one organization that
     * nobody is ever shown.
     *
     * Keeping the machinery on in both modes is deliberate. A code path
     * exercised only by managed-service installations is a code path nobody
     * tests, and this one decides who can open whose locker.
     */
    'multi_organization' => (bool) env('MULTI_ORGANIZATION', false),
];
