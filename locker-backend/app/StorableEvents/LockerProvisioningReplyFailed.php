<?php

declare(strict_types=1);

namespace App\StorableEvents;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class LockerProvisioningReplyFailed extends ShouldBeStored
{
    /**
     * @param  string  $lockerBankUuid  The UUID of the locker bank that was being provisioned
     * @param  string  $replyToTopic  The reply topic which was targeted
     * @param  string  $reason  Human-readable reason for the failure
     */
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $replyToTopic,
        public readonly string $reason,
    ) {}
}
