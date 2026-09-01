<?php

declare(strict_types=1);

namespace App\Aggregates;

use App\Models\Compartment;
use App\Models\LockerBank;
use App\StorableEvents\CompartmentOpeningRequested;
use App\StorableEvents\LockerConfigApplyRequested;
use App\StorableEvents\LockerProvisioningFailed;
use App\StorableEvents\LockerProvisioningReset;
use App\StorableEvents\LockerProvisioningTokenIssued;
use App\StorableEvents\LockerWasProvisioned;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LockerBankAggregate extends TransactionalAggregateRoot
{
    // We will add methods here to handle commands like
    // `registerLockerBank` which will then record events
    // like `LockerBankWasProvisioned`.

    public function provision(
        LockerBank $lockerBank,
        string $replyToTopic,
        string $provisioningGeneration,
    ): self {
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
            provisioningGeneration: $provisioningGeneration,
        ));
        Log::info("LockerWasProvisioned event recorded for: {$lockerBank->id}");

        return $this;
    }

    /**
     * Record that a locker bank's provisioning was reset, so the device must
     * re-authenticate. The new token must still be delivered to the device
     * manually (the backend has no push channel).
     *
     * Rotating the token, deleting the MQTT user and enforcing authorization
     * belong to {@see \App\Services\LockerProvisioningService}.
     */
    public function resetProvisioning(int $actorUserId, CarbonImmutable $resetAt): self
    {
        Log::info("Resetting provisioning for locker bank: {$this->uuid()}");

        $this->recordThat(new LockerProvisioningReset(
            lockerBankUuid: (string) $this->uuid(),
            actorUserId: $actorUserId,
            resetAtIso8601: $resetAt->toIso8601String(),
        ));

        return $this;
    }

    public function issueProvisioningToken(
        string $provisioningTokenHmac,
        string $provisioningGeneration,
        int $actorUserId,
        CarbonImmutable $issuedAt,
    ): self {
        $this->recordThat(new LockerProvisioningTokenIssued(
            lockerBankUuid: (string) $this->uuid(),
            provisioningTokenHmac: $provisioningTokenHmac,
            provisioningGeneration: $provisioningGeneration,
            actorUserId: $actorUserId,
            issuedAtIso8601: $issuedAt->toIso8601String(),
        ));

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
    public function requestApplyConfig(
        string $configHash,
        int $heartbeatIntervalSeconds,
        string $adapterType,
        int $channelCount,
        string $feedbackType,
        array $compartments,
    ): self {
        $lockerBankUuid = (string) $this->uuid();
        $commandId = (string) Str::uuid();

        Log::info('Recording LockerConfigApplyRequested event', [
            'lockerBankUuid' => $lockerBankUuid,
            'commandId' => $commandId,
            'configHash' => $configHash,
            'heartbeatIntervalSeconds' => $heartbeatIntervalSeconds,
            'adapterType' => $adapterType,
            'channelCount' => $channelCount,
            'feedbackType' => $feedbackType,
            'compartmentCount' => count($compartments),
        ]);

        $this->recordThat(new LockerConfigApplyRequested(
            lockerBankUuid: $lockerBankUuid,
            commandId: $commandId,
            configHash: $configHash,
            heartbeatIntervalSeconds: $heartbeatIntervalSeconds,
            adapterType: $adapterType,
            channelCount: $channelCount,
            feedbackType: $feedbackType,
            compartments: $compartments,
        ));

        return $this;
    }
}
