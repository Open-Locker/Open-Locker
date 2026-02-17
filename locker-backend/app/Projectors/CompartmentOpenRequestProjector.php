<?php

declare(strict_types=1);

namespace App\Projectors;

use App\Models\CompartmentOpenRequest;
use App\StorableEvents\CompartmentOpenAuthorized;
use App\StorableEvents\CompartmentOpenDenied;
use App\StorableEvents\CompartmentOpened;
use App\StorableEvents\CompartmentOpeningFailed;
use App\StorableEvents\CompartmentOpeningRequested;
use App\StorableEvents\CompartmentOpenRequested;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class CompartmentOpenRequestProjector extends Projector implements ShouldQueue
{
    public function onCompartmentOpenRequested(CompartmentOpenRequested $event): void
    {
        CompartmentOpenRequest::query()->updateOrCreate(
            ['command_id' => $event->commandId],
            [
                'actor_user_id' => $event->actorUserId,
                'compartment_id' => $event->compartmentUuid,
                'status' => 'requested',
                'requested_at' => now(),
            ]
        );
    }

    public function onCompartmentOpenAuthorized(CompartmentOpenAuthorized $event): void
    {
        CompartmentOpenRequest::query()->updateOrCreate(
            ['command_id' => $event->commandId],
            [
                'actor_user_id' => $event->actorUserId,
                'compartment_id' => $event->compartmentUuid,
                'authorization_type' => $event->authorizationType,
                'status' => 'accepted',
                'accepted_at' => now(),
                'denied_reason' => null,
            ]
        );
    }

    public function onCompartmentOpenDenied(CompartmentOpenDenied $event): void
    {
        CompartmentOpenRequest::query()->updateOrCreate(
            ['command_id' => $event->commandId],
            [
                'actor_user_id' => $event->actorUserId,
                'compartment_id' => $event->compartmentUuid,
                'status' => 'denied',
                'denied_reason' => $event->reason,
                'denied_at' => now(),
            ]
        );
    }

    public function onCompartmentOpeningRequested(CompartmentOpeningRequested $event): void
    {
        CompartmentOpenRequest::query()
            ->where('command_id', $event->commandId)
            ->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
    }

    public function onCompartmentOpened(CompartmentOpened $event): void
    {
        CompartmentOpenRequest::query()
            ->where('command_id', $event->transactionId)
            ->update([
                'status' => 'opened',
                'opened_at' => $event->timestamp ? Carbon::parse($event->timestamp) : now(),
                'error_code' => null,
                'error_message' => null,
            ]);
    }

    public function onCompartmentOpeningFailed(CompartmentOpeningFailed $event): void
    {
        CompartmentOpenRequest::query()
            ->where('command_id', $event->transactionId)
            ->update([
                'status' => 'failed',
                'failed_at' => $event->timestamp ? Carbon::parse($event->timestamp) : now(),
                'error_code' => $event->errorCode,
                'error_message' => $event->message,
            ]);
    }
}
