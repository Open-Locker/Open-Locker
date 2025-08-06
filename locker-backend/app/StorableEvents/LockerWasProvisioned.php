<?php

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LockerWasProvisioned extends ShouldBeStored
{
    /**
     * @param  string  $lockerBankUuid  The UUID of the locker bank that was provisioned.
     * @param  string  $replyToTopic  The private MQTT topic to send the credentials back to.
     */
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $replyToTopic,
    ) {}
}
