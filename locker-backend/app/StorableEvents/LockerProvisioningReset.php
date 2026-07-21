<?php

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LockerProvisioningReset extends ShouldBeStored
{
    /**
     * Records an admin-initiated reset of a locker bank's provisioning so the
     * device must re-authenticate. Delivery of the new token to the device is
     * manual (the backend has no channel to push it down).
     *
     * @param  string  $lockerBankUuid  The UUID of the locker bank being reset.
     * @param  string  $newProvisioningToken  The freshly minted token replacing the old one.
     */
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $newProvisioningToken,
    ) {}
}
