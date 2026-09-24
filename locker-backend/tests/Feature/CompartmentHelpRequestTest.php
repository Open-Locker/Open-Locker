<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use App\Models\Compartment;
use App\Models\User;
use App\Notifications\CompartmentHelpRequestedNotification;
use App\Services\CompartmentAccessService;
use App\StorableEvents\CompartmentHelpRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class CompartmentHelpRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_help_request_is_recorded_and_sent_to_the_operators(): void
    {
        Notification::fake();
        [$admin, $manager] = $this->givenOperators();
        $user = $this->givenRegularUser();
        $compartment = Compartment::factory()->create();
        app(CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);

        $response = $this->actingAs($user)->postJson(
            route('compartments.help-requests.store', $compartment->id),
            ['message' => '  Door is stuck, my bag is inside.  '],
        );

        $response->assertStatus(202)->assertJsonPath('status', true);
        $event = EloquentStoredEvent::query()
            ->where('event_class', CompartmentHelpRequested::class)
            ->sole();
        $this->assertSame([
            'helpRequestUuid' => $response->json('help_request_id'),
            'compartmentUuid' => (string) $compartment->id,
            'actorUserId' => $user->id,
            'message' => 'Door is stuck, my bag is inside.',
        ], array_intersect_key($event->event_properties, array_flip([
            'helpRequestUuid', 'compartmentUuid', 'actorUserId', 'message',
        ])));
        Notification::assertSentTo([$admin, $manager], CompartmentHelpRequestedNotification::class);
        Notification::assertNotSentTo($user, CompartmentHelpRequestedNotification::class);
    }

    public function test_a_user_without_access_to_the_compartment_is_refused(): void
    {
        Notification::fake();
        $user = $this->givenRegularUser();
        $compartment = Compartment::factory()->create();

        $this->actingAs($user)->postJson(
            route('compartments.help-requests.store', $compartment->id),
            ['message' => 'Let me in.'],
        )->assertStatus(403);

        $this->assertSame(0, EloquentStoredEvent::query()
            ->where('event_class', CompartmentHelpRequested::class)
            ->count());
    }

    public function test_a_blank_message_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();
        $compartment = Compartment::factory()->create();

        $this->actingAs($admin)->postJson(
            route('compartments.help-requests.store', $compartment->id),
            ['message' => '   '],
        )->assertStatus(422)->assertJsonValidationErrors('message');
    }

    private function givenRegularUser(): User
    {
        User::factory()->create(); // the first user may become admin automatically

        $user = User::factory()->create();
        $user->removeAdmin();

        return $user;
    }

    /**
     * @return array{0: User, 1: User} admin, manager
     */
    private function givenOperators(): array
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $manager = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($manager->id))
            ->grantRole($manager->id, Role::Manager->value, $admin->id, now())
            ->persist();

        return [$admin->fresh(), $manager->fresh()];
    }
}
