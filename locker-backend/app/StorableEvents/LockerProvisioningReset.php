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
     * The new token is deliberately absent. The event store is append-only and
     * readable by anyone who can read the database or run a replay, and a
     * provisioning token is a credential — so the reset service writes it
     * straight to the locker-bank row instead. Tokens therefore stay outside
     * event replay, which matches how they already behave: a bank's first token
     * is minted in a `creating` hook, so a clean replay could not reconstruct
     * it either.
     *
     * @param  string  $lockerBankUuid  The UUID of the locker bank being reset.
     * @param  int|null  $actorUserId  The admin who initiated the reset, if known.
     * @param  string  $resetAtIso8601  When the reset happened.
     */
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly ?int $actorUserId,
        public readonly string $resetAtIso8601,
    ) {}
}
