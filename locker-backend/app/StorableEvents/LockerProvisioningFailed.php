<?php

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LockerProvisioningFailed extends ShouldBeStored
{
    /**
     * @param  string  $replyToTopic  The private MQTT topic to send the rejection message back to.
     * @param  string  $reason  A developer-friendly reason for the failure.
     */
    public function __construct(
        public readonly string $replyToTopic,
        public readonly string $reason,
    ) {}
}
