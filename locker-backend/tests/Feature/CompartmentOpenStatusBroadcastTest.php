<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\CompartmentOpenStatusUpdated;
use App\Reactors\CompartmentOpenStatusBroadcastReactor;
use App\StorableEvents\CompartmentOpenAuthorized;
use App\StorableEvents\CompartmentOpenDenied;
use App\StorableEvents\CompartmentOpened;
use App\StorableEvents\CompartmentOpeningRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class CompartmentOpenStatusBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_opened_event_broadcasts_status_to_requesting_user(): void
    {
        Event::fake([CompartmentOpenStatusUpdated::class]);

        $commandId = '11111111-1111-1111-1111-111111111111';
        $userId = 42;
        $compartmentId = '22222222-2222-2222-2222-222222222222';

        EloquentStoredEvent::query()->create([
            'aggregate_uuid' => $commandId,
            'aggregate_version' => 2,
            'event_version' => 1,
            'event_class' => CompartmentOpenAuthorized::class,
            'event_properties' => [
                'commandId' => $commandId,
                'actorUserId' => $userId,
                'compartmentUuid' => $compartmentId,
                'authorizationType' => 'granted_access',
            ],
            'meta_data' => [],
            'created_at' => now(),
        ]);

        app(CompartmentOpenStatusBroadcastReactor::class)->onCompartmentOpened(new CompartmentOpened(
            lockerBankUuid: '33333333-3333-3333-3333-333333333333',
            compartmentUuid: $compartmentId,
            compartmentNumber: 7,
            transactionId: $commandId
        ));

        Event::assertDispatched(CompartmentOpenStatusUpdated::class, function (CompartmentOpenStatusUpdated $event) use ($userId, $commandId, $compartmentId): bool {
            return $event->userId === $userId
                && $event->commandId === $commandId
                && $event->compartmentUuid === $compartmentId
                && $event->status === 'opened';
        });
    }

    public function test_denied_event_broadcasts_denied_status(): void
    {
        Event::fake([CompartmentOpenStatusUpdated::class]);

        app(CompartmentOpenStatusBroadcastReactor::class)->onCompartmentOpenDenied(new CompartmentOpenDenied(
            commandId: 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
            actorUserId: 9,
            compartmentUuid: 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
            reason: 'missing_active_access'
        ));

        Event::assertDispatched(CompartmentOpenStatusUpdated::class, function (CompartmentOpenStatusUpdated $event): bool {
            return $event->userId === 9
                && $event->status === 'denied'
                && $event->message === 'missing_active_access';
        });
    }

    public function test_sent_event_broadcasts_after_command_was_dispatched(): void
    {
        Event::fake([CompartmentOpenStatusUpdated::class]);

        $commandId = 'aaaaaaaa-1111-2222-3333-bbbbbbbbbbbb';
        $userId = 77;
        $compartmentId = 'cccccccc-1111-2222-3333-dddddddddddd';

        EloquentStoredEvent::query()->create([
            'aggregate_uuid' => $commandId,
            'aggregate_version' => 2,
            'event_version' => 1,
            'event_class' => CompartmentOpenAuthorized::class,
            'event_properties' => [
                'commandId' => $commandId,
                'actorUserId' => $userId,
                'compartmentUuid' => $compartmentId,
                'authorizationType' => 'admin_override',
            ],
            'meta_data' => [],
            'created_at' => now(),
        ]);

        app(CompartmentOpenStatusBroadcastReactor::class)->onCompartmentOpeningRequested(new CompartmentOpeningRequested(
            lockerBankUuid: 'eeeeeeee-1111-2222-3333-ffffffffffff',
            compartmentUuid: $compartmentId,
            compartmentNumber: 1,
            commandId: $commandId
        ));

        Event::assertDispatched(CompartmentOpenStatusUpdated::class, function (CompartmentOpenStatusUpdated $event) use ($userId, $commandId): bool {
            return $event->userId === $userId
                && $event->commandId === $commandId
                && $event->status === 'sent';
        });
    }
}
