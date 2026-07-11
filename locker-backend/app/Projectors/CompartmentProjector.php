<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Enums\CompartmentDoorState;
use App\Models\Compartment;
use App\StorableEvents\CompartmentContentNoteUpdated;
use App\StorableEvents\CompartmentDoorStateChanged;
use App\StorableEvents\CompartmentOpened;
use App\StorableEvents\CompartmentOpeningFailed;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class CompartmentProjector extends Projector
{
    public function onCompartmentDoorStateChanged(CompartmentDoorStateChanged $event): void
    {
        $compartment = Compartment::find($event->compartmentUuid);
        if (! $compartment) {
            return;
        }

        $ts = Carbon::parse($event->doorStateChangedAtIso8601);

        $compartment->forceFill([
            'door_state' => CompartmentDoorState::from($event->newDoorState),
            'door_state_changed_at' => $ts,
        ])->save();
    }

    public function onCompartmentOpened(CompartmentOpened $event): void
    {
        $compartment = Compartment::find($event->compartmentUuid);
        if (! $compartment) {
            return;
        }

        $ts = $event->timestamp ? Carbon::parse($event->timestamp) : now();

        $compartment->forceFill([
            'last_opened_at' => $ts,
            'last_open_failed_at' => null,
            'last_open_transaction_id' => $event->transactionId,
            'last_open_error_code' => null,
            'last_open_error_message' => null,
        ])->save();
    }

    public function onCompartmentOpeningFailed(CompartmentOpeningFailed $event): void
    {
        $compartment = Compartment::find($event->compartmentUuid);
        if (! $compartment) {
            return;
        }

        $ts = $event->timestamp ? Carbon::parse($event->timestamp) : now();

        $compartment->forceFill([
            'last_open_failed_at' => $ts,
            'last_open_transaction_id' => $event->transactionId,
            'last_open_error_code' => $event->errorCode,
            'last_open_error_message' => $event->message,
        ])->save();
    }

    public function onCompartmentContentNoteUpdated(CompartmentContentNoteUpdated $event): void
    {
        $compartment = Compartment::find($event->compartmentUuid);
        if (! $compartment) {
            return;
        }

        $compartment->forceFill([
            'content_note' => $event->note,
            'content_note_updated_at' => Carbon::parse($event->updatedAtIso8601),
            'content_note_updated_by_user_id' => $event->actorUserId,
        ])->save();
    }
}
