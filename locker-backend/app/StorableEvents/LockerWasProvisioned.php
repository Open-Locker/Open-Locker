<?php

declare(strict_types=1);

namespace App\StorableEvents;

use App\Support\Audit\AuditCategory;
use App\Support\Audit\Audited;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

#[Audited(AuditCategory::Devices, 'Locker provisioned')]
class LockerWasProvisioned extends ShouldBeStored
{
    /**
     * @param  string  $lockerBankUuid  The UUID of the locker bank that was provisioned.
     * @param  string  $replyToTopic  The private MQTT topic to send the credentials back to.
     * @param  string|null  $provisioningGeneration  Correlates delayed reactor work with the current device generation.
     *                                               Null keeps pre-generation stored events replayable.
     */
    public function __construct(
        public readonly string $lockerBankUuid,
        public readonly string $replyToTopic,
        public readonly ?string $provisioningGeneration = null,
    ) {}
}
