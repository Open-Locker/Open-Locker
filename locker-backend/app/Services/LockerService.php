<?php

namespace App\Services;

use App\Models\Compartment;
use Illuminate\Support\Facades\Log;

class LockerService
{
    /**
     * This method will be responsible for dispatching a command to open a compartment
     * via MQTT. The actual implementation will be done using Event Sourcing Reactors.
     */
    public function openCompartment(Compartment $compartment): void
    {
        Log::info("Dispatching command to open compartment: {$compartment->name} (UUID: {$compartment->uuid})");
        // In the future, this will be handled by a reactor listening for a 'DoorOpeningRequested' event.
        // For now, we just log the action.
    }
}
