<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\LockerBankAggregate;
use App\Models\Compartment;
use App\Models\LockerBank;
use Illuminate\Support\Facades\Log;

class LockerService
{
    /**
     * Request opening a compartment via Event Sourcing (Reactor will publish MQTT).
     *
     * This service is the hardware command boundary and should stay focused on
     * locker-device interactions (MQTT/Modbus command flow).
     *
     * For business rules (authorization, admin override, command lifecycle),
     * use CompartmentAccessService and related domain services first.
     */
    public function openCompartment(Compartment $compartment, ?string $commandId = null): void
    {
        $lockerBankUuid = (string) $compartment->locker_bank_id;

        Log::info('LockerService::openCompartment requested', [
            'lockerBankUuid' => $lockerBankUuid,
            'compartmentUuid' => (string) $compartment->id,
            'compartmentNumber' => (int) $compartment->number,
            'commandId' => $commandId,
        ]);

        LockerBankAggregate::retrieve($lockerBankUuid)
            ->requestCompartmentOpening($compartment, $commandId)
            ->persist();
    }

    /**
     * Request the client to apply the current compartment addressing config.
     *
     * @throws \RuntimeException when configuration is incomplete
     */
    public function applyConfig(LockerBank $lockerBank): void
    {
        $payload = $lockerBank->buildApplyConfigPayload();
        $configHash = $payload['config_hash'];

        $missing = $lockerBank->compartments()
            ->whereNull('slave_id')
            ->orWhereNull('address')
            ->count();

        if ($missing > 0) {
            throw new \RuntimeException('Config is incomplete: every compartment needs slave_id and address.');
        }

        Log::info('LockerService::applyConfig requested', [
            'lockerBankUuid' => (string) $lockerBank->id,
            'configHash' => $configHash,
            'compartmentCount' => count($payload['compartments']),
        ]);

        LockerBankAggregate::retrieve((string) $lockerBank->id)
            ->requestApplyConfig($configHash, (int) $lockerBank->heartbeat_interval_seconds, $payload['compartments'])
            ->persist();

        $lockerBank->update([
            'last_config_sent_at' => now(),
            'last_config_sent_hash' => $configHash,
        ]);
    }
}
