<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\LockerBankAggregate;
use App\Enums\LockerAdapterType;
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
        $missing = $lockerBank->compartments()
            ->where(function ($query): void {
                $query->whereNull('slave_id')
                    ->orWhereNull('address');
            })
            ->count();

        if ($missing > 0) {
            throw new \RuntimeException('Config is incomplete: every compartment needs slave_id and address.');
        }

        $maxAddress = LockerBank::MAX_WIRE_CHANNEL_ADDRESS;
        $outOfRange = $lockerBank->compartments()
            ->where(function ($query) use ($maxAddress): void {
                $query->where('address', '<', 0)
                    ->orWhere('address', '>', $maxAddress);
            })
            ->count();

        if ($outOfRange > 0) {
            throw new \RuntimeException("Config is invalid: every compartment address must be between 0 and {$maxAddress} (wire-encodable channel).");
        }

        if (
            $lockerBank->adapter_type === LockerAdapterType::Rs485LockBoard
            && $lockerBank->compartments()
                ->where(function ($query): void {
                    $query->where('slave_id', '<', 1)
                        ->orWhere('slave_id', '>', 31);
                })
                ->exists()
        ) {
            throw new \RuntimeException('Config is invalid: RS485 locker board slave_id must be between 1 and 31.');
        }

        $payload = $lockerBank->buildApplyConfigPayload();
        $configHash = $payload['config_hash'];

        Log::info('LockerService::applyConfig requested', [
            'lockerBankUuid' => (string) $lockerBank->id,
            'configHash' => $configHash,
            'adapterType' => $payload['adapter_type'],
            'feedbackType' => $payload['feedback_type'],
            'compartmentCount' => count($payload['compartments']),
        ]);

        LockerBankAggregate::retrieve((string) $lockerBank->id)
            ->requestApplyConfig(
                $configHash,
                (int) $lockerBank->heartbeat_interval_seconds,
                $payload['adapter_type'],
                $payload['feedback_type'],
                $payload['compartments'],
            )
            ->persist();

        $lockerBank->update([
            'last_config_sent_at' => now(),
            'last_config_sent_hash' => $configHash,
        ]);
    }
}
