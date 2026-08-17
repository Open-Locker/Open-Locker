<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LockerProvisioningReset extends ShouldBeStored
{
    /**
     * Records an admin-initiated reset of a locker bank's provisioning so the
     * device must re-authenticate. Delivery of the new token to the device is
     * manual (the backend has no channel to push it down).
     *
     * The plaintext token is deliberately absent. A following
     * LockerProvisioningTokenIssued event carries only its HMAC and generation,
     * making the read model replayable without retaining the credential.
     *
     * @param  string  $lockerBankUuid  The UUID of the locker bank being reset.
     * @param  int  $actorUserId  The admin who initiated the reset.
     * @param  string  $resetAtIso8601  When the reset happened.
     */
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly int $actorUserId,
        public readonly string $resetAtIso8601,
    ) {}
}
