<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Events\CompartmentDoorStateUpdated;
use App\Models\Compartment;
use App\Services\CompartmentStatusBroadcastService;
use App\StorableEvents\CompartmentDoorStateChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * Broadcasts compartment door updates after projection — Laravel notifications stay side-effects of stored facts.
 */
class CompartmentDoorStateBroadcastReactor extends Reactor implements ShouldQueue
{
    public string $queue = 'events';

    public function __construct(
        private readonly CompartmentStatusBroadcastService $broadcastService,
    ) {}

    public function onCompartmentDoorStateChanged(CompartmentDoorStateChanged $event): void
    {
        $compartment = Compartment::find($event->compartmentUuid);
        if (! $compartment) {
            return;
        }

        $recipientIds = $this->broadcastService->recipientUserIdsForCompartment($compartment);
        if ($recipientIds === []) {
            return;
        }

        event(new CompartmentDoorStateUpdated(
            recipientUserIds: $recipientIds,
            compartmentUuid: $event->compartmentUuid,
            doorState: $event->newDoorState,
            doorStateChangedAtIso: $event->doorStateChangedAtIso8601,
        ));
    }
}
