<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Models\Compartment;
use App\StorableEvents\CompartmentOpened;
use App\StorableEvents\CompartmentOpeningFailed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class CompartmentProjector extends Projector implements ShouldQueue
{
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
}
