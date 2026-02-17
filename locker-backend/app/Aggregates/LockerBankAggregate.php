<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\Models\Compartment;
use App\Models\LockerBank;
use App\StorableEvents\CompartmentOpeningRequested;
use App\StorableEvents\LockerConfigApplyRequested;
use App\StorableEvents\LockerProvisioningFailed;
use App\StorableEvents\LockerWasProvisioned;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /**
     * Record a request to open a specific compartment within a locker bank.
     * The actual side-effect (publishing an MQTT command) is handled by a Reactor.
     */
    public function requestCompartmentOpening(Compartment $compartment, ?string $commandId = null): self
    {
        $lockerBankUuid = (string) $compartment->locker_bank_id;
        $compartmentUuid = (string) $compartment->id;
        $compartmentNumber = (int) $compartment->number;
        $commandId = $commandId ?: (string) Str::uuid();

        Log::info('Recording CompartmentOpeningRequested event', [
            'lockerBankUuid' => $lockerBankUuid,
            'compartmentUuid' => $compartmentUuid,
            'compartmentNumber' => $compartmentNumber,
            'commandId' => $commandId,
        ]);

        $this->recordThat(new CompartmentOpeningRequested(
            lockerBankUuid: $lockerBankUuid,
            compartmentUuid: $compartmentUuid,
            compartmentNumber: $compartmentNumber,
            commandId: $commandId,
        ));

        return $this;
    }

    /**
     * Record a request to apply the current addressing config on the client.
     *
     * @param  array<int, array<string, int>>  $compartments
     */
    public function requestApplyConfig(string $configHash, int $heartbeatIntervalSeconds, array $compartments): self
    {
        $lockerBankUuid = (string) $this->uuid();
        $commandId = (string) Str::uuid();

        Log::info('Recording LockerConfigApplyRequested event', [
            'lockerBankUuid' => $lockerBankUuid,
            'commandId' => $commandId,
            'configHash' => $configHash,
            'heartbeatIntervalSeconds' => $heartbeatIntervalSeconds,
            'compartmentCount' => count($compartments),
        ]);

        $this->recordThat(new LockerConfigApplyRequested(
            lockerBankUuid: $lockerBankUuid,
            commandId: $commandId,
            configHash: $configHash,
            heartbeatIntervalSeconds: $heartbeatIntervalSeconds,
            compartments: $compartments,
        ));

        return $this;
    }
}
