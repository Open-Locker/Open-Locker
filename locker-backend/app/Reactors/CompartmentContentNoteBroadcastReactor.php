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

        event(new CompartmentNoteUpdated(
            recipientUserIds: $recipientIds,
            compartmentUuid: $event->compartmentUuid,
            note: $event->note,
            noteUpdatedAtIso: $event->updatedAtIso8601,
            noteUpdatedByUserId: $event->actorUserId,
        ));
    }
}
