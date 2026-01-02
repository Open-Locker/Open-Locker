<?php

declare(strict_types=1);

namespace App\Services;

use App\Aggregates\LockerBankAggregate;
use App\Models\Compartment;
use Illuminate\Support\Facades\Log;

class LockerService
{
    /**
     * Request opening a compartment via Event Sourcing (Reactor will publish MQTT).
     *
     * This is the "simple function" developers can call without knowing the
     * Event Sourcing / MQTT details.
     */
    public function openCompartment(Compartment $compartment): void
    {
        $lockerBankUuid = (string) $compartment->locker_bank_id;

        Log::info('LockerService::openCompartment requested', [
            'lockerBankUuid' => $lockerBankUuid,
            'compartmentUuid' => (string) $compartment->id,
            'compartmentNumber' => (int) $compartment->number,
        ]);

        LockerBankAggregate::retrieve($lockerBankUuid)
            ->requestCompartmentOpening($compartment)
            ->persist();
    }
}
