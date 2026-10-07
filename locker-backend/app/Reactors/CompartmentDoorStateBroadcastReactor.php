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
        // Queued, so no organization is in context. The uuid comes from the
        // event being handled rather than from a request, and it is globally
        // unique — so there is nothing here for a scope to protect. Scoped,
        // this resolves to nothing and the broadcast is silently skipped: the
        // app shows a stale door until someone reloads the screen.
        $compartment = Compartment::withoutGlobalScope('organization')->find($event->compartmentUuid);
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
