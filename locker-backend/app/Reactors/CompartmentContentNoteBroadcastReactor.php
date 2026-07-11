<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Events\CompartmentNoteUpdated;
use App\Models\Compartment;
use App\Services\CompartmentStatusBroadcastService;
use App\StorableEvents\CompartmentContentNoteUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * Broadcasts compartment content-note updates after projection — the note lives
 * only in the read model (no hardware path), so this mirrors the door-state
 * broadcast and keeps the realtime push a side-effect of the stored fact.
 */
class CompartmentContentNoteBroadcastReactor extends Reactor implements ShouldQueue
{
    public string $queue = 'events';

    public function __construct(
        private readonly CompartmentStatusBroadcastService $broadcastService,
    ) {}

    public function onCompartmentContentNoteUpdated(CompartmentContentNoteUpdated $event): void
    {
        $compartment = Compartment::find($event->compartmentUuid);
        if (! $compartment) {
            return;
        }

        $recipientIds = $this->broadcastService->recipientUserIdsForCompartment($compartment);
        if ($recipientIds === []) {
            return;
        }

        event(new CompartmentNoteUpdated(
            recipientUserIds: $recipientIds,
            compartmentUuid: $event->compartmentUuid,
            note: $event->note,
            noteUpdatedAtIso: $event->updatedAtIso8601,
            noteUpdatedByUserId: $event->actorUserId,
        ));
    }
}
