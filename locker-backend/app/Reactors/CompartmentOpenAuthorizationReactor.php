<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Models\Compartment;
use App\Services\LockerService;
use App\StorableEvents\CompartmentOpenAuthorized;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class CompartmentOpenAuthorizationReactor extends Reactor implements ShouldQueue
{
    public string $queue = 'events';

    public function onCompartmentOpenAuthorized(CompartmentOpenAuthorized $event): void
    {
        // Queued, so no organization is in context; the uuid comes from the
        // event rather than from a request.
        $compartment = Compartment::withoutGlobalScope('organization')->find($event->compartmentUuid);
        if (! $compartment) {
            Log::warning('Authorized open request references unknown compartment', [
                'commandId' => $event->commandId,
                'actorUserId' => $event->actorUserId,
                'compartmentUuid' => $event->compartmentUuid,
            ]);

            return;
        }

        app(LockerService::class)->openCompartment($compartment, $event->commandId);
    }
}
