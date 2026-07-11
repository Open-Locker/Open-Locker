<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\CompartmentNoteUpdated;
use App\Models\Compartment;
use App\Models\User;
use App\Reactors\CompartmentContentNoteBroadcastReactor;
use App\Services\CompartmentAccessService;
use App\StorableEvents\CompartmentContentNoteUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CompartmentNoteBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function createRegularUser(): User
    {
        User::factory()->create(); // first user may become admin automatically

        $user = User::factory()->create();
        $user->removeAdmin();

        return $user;
    }

    private function createAdminUser(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    public function test_note_update_broadcasts_to_active_access_holder(): void
    {
        Event::fake([CompartmentNoteUpdated::class]);

        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);

        app(CompartmentContentNoteBroadcastReactor::class)->onCompartmentContentNoteUpdated(
            new CompartmentContentNoteUpdated(
                compartmentUuid: (string) $compartment->id,
                actorUserId: $user->id,
                note: 'Keys are inside',
                updatedAtIso8601: '2026-06-24T10:00:00+00:00',
            )
        );

        Event::assertDispatched(CompartmentNoteUpdated::class, function (CompartmentNoteUpdated $event) use ($user, $compartment): bool {
            return $event->compartmentUuid === (string) $compartment->id
                && $event->note === 'Keys are inside'
                && $event->noteUpdatedByUserId === $user->id
                && in_array($user->id, $event->recipientUserIds, true);
        });
    }

    public function test_no_broadcast_when_compartment_missing(): void
    {
        Event::fake([CompartmentNoteUpdated::class]);

        app(CompartmentContentNoteBroadcastReactor::class)->onCompartmentContentNoteUpdated(
            new CompartmentContentNoteUpdated(
                compartmentUuid: '00000000-0000-0000-0000-000000000000',
                actorUserId: 1,
                note: 'orphan',
                updatedAtIso8601: '2026-06-24T10:00:00+00:00',
            )
        );

        Event::assertNotDispatched(CompartmentNoteUpdated::class);
    }
}
