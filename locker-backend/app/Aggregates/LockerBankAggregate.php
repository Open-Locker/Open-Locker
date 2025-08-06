<?php

namespace App\Aggregates;

use App\Models\LockerBank;
use App\StorableEvents\LockerProvisioningFailed;
use App\StorableEvents\LockerWasProvisioned;
use Illuminate\Support\Facades\Log;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class LockerBankAggregate extends AggregateRoot
{
    // We will add methods here to handle commands like
    // `registerLockerBank` which will then record events
    // like `LockerBankWasProvisioned`.

    public function provision(LockerBank $lockerBank, string $replyToTopic): self
    {
        // The check for the existence of the locker bank is now done in the command.
        // We can directly proceed with the provisioning logic.
        Log::info("Provisioning locker bank: {$lockerBank->id}");

        if ($lockerBank->provisioned_at) {
            Log::warning("Locker bank is already provisioned: {$lockerBank->id}");
            $this->recordThat(new LockerProvisioningFailed(
                replyToTopic: $replyToTopic,
                reason: 'Locker bank is already provisioned.'
            ));

            return $this;
        }

        // The aggregate's only job is to record that the provisioning was successful.
        // The side effect (creating the MQTT user) is handled by the MqttReactor.
        $this->recordThat(new LockerWasProvisioned(
            lockerBankUuid: $lockerBank->id,
            replyToTopic: $replyToTopic,
        ));
        Log::info("LockerWasProvisioned event recorded for: {$lockerBank->id}");

        return $this;
    }
}
