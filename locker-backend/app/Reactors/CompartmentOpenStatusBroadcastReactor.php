<?php

declare(strict_types=1);

namespace App\Reactors;

use App\Events\CompartmentOpenStatusUpdated;
use App\StorableEvents\CompartmentOpenAuthorized;
use App\StorableEvents\CompartmentOpenDenied;
use App\StorableEvents\CompartmentOpened;
use App\StorableEvents\CompartmentOpeningFailed;
use App\StorableEvents\CompartmentOpeningRequested;
use Illuminate\Contracts\Queue\ShouldQueue;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

class CompartmentOpenStatusBroadcastReactor extends Reactor implements ShouldQueue
{
    public string $queue = 'events';

    public function onCompartmentOpenAuthorized(CompartmentOpenAuthorized $event): void
    {
        event(new CompartmentOpenStatusUpdated(
            userId: $event->actorUserId,
            commandId: $event->commandId,
            compartmentUuid: $event->compartmentUuid,
            status: 'accepted'
        ));
    }

    public function onCompartmentOpeningRequested(CompartmentOpeningRequested $event): void
    {
        $userId = $this->actorIdForCommand($event->commandId);
        if (! $userId) {
            return;
        }

        event(new CompartmentOpenStatusUpdated(
            userId: $userId,
            commandId: $event->commandId,
            compartmentUuid: $event->compartmentUuid,
            status: 'sent'
        ));
    }

    public function onCompartmentOpened(CompartmentOpened $event): void
    {
        $userId = $this->actorIdForCommand($event->transactionId);
        if (! $userId) {
            return;
        }

        event(new CompartmentOpenStatusUpdated(
            userId: $userId,
            commandId: $event->transactionId,
            compartmentUuid: $event->compartmentUuid,
            status: 'opened'
        ));
    }

    public function onCompartmentOpeningFailed(CompartmentOpeningFailed $event): void
    {
        $userId = $this->actorIdForCommand($event->transactionId);
        if (! $userId) {
            return;
        }

        event(new CompartmentOpenStatusUpdated(
            userId: $userId,
            commandId: $event->transactionId,
            compartmentUuid: $event->compartmentUuid,
            status: 'failed',
            errorCode: $event->errorCode,
            message: $event->message
        ));
    }

    public function onCompartmentOpenDenied(CompartmentOpenDenied $event): void
    {
        event(new CompartmentOpenStatusUpdated(
            userId: $event->actorUserId,
            commandId: $event->commandId,
            compartmentUuid: $event->compartmentUuid,
            status: 'denied',
            message: $event->reason
        ));
    }

    private function actorIdForCommand(string $commandId): ?int
    {
        $authorizedEvent = EloquentStoredEvent::query()
            ->where('event_class', CompartmentOpenAuthorized::class)
            ->where('event_properties->commandId', $commandId)
            ->latest('id')
            ->first();

        if (! $authorizedEvent) {
            return null;
        }

        return (int) ($authorizedEvent->event_properties['actorUserId'] ?? 0) ?: null;
    }
}
