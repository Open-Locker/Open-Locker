<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Compartment;
use App\Models\User;
use App\Services\CompartmentStatusBroadcastService;
use App\Services\GroupAccessService;
use App\Services\LockerService;
use App\StorableEvents\CompartmentOpenAuthorized;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class GroupEffectiveAccessTest extends TestCase
{
    use RefreshDatabase;

    private function createRegularUser(): User
    {
        User::factory()->create(); // first user may become admin automatically

        $user = User::factory()->create();
        $user->is_admin_since = null;
        $user->save();

        return $user;
    }

    private function createAdminUser(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    private function groupService(): GroupAccessService
    {
        return app(GroupAccessService::class);
    }

    private function grantGroupAccess(User $user, Compartment $compartment, User $admin): void
    {
        $group = $this->groupService()->createGroup('Team', actor: $admin);
        $this->groupService()->addUser($group, $user, actor: $admin);
        $this->groupService()->grantCompartmentAccess($group, $compartment, actor: $admin);
    }

    public function test_group_only_member_can_open_with_group_access_authorization(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();
        $this->grantGroupAccess($user, $compartment, $admin);

        $this->mock(LockerService::class, function ($mock): void {
            $mock->shouldReceive('openCompartment')->once();
        });

        $response = $this->actingAs($user)->postJson(route('compartments.open', $compartment->id));

        $response->assertStatus(202);

        /** @var ?EloquentStoredEvent $authorizedEvent */
        $authorizedEvent = EloquentStoredEvent::query()
            ->where('event_class', CompartmentOpenAuthorized::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($authorizedEvent);
        $this->assertSame('group_access', $authorizedEvent->event_properties['authorizationType'] ?? null);
    }

    public function test_direct_access_takes_precedence_over_group_access(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        // User reachable both directly and via a group.
        app(\App\Services\CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);
        $this->grantGroupAccess($user, $compartment, $admin);

        $this->mock(LockerService::class, function ($mock): void {
            $mock->shouldReceive('openCompartment')->once();
        });

        $response = $this->actingAs($user)->postJson(route('compartments.open', $compartment->id));

        $response->assertStatus(202);

        /** @var ?EloquentStoredEvent $authorizedEvent */
        $authorizedEvent = EloquentStoredEvent::query()
            ->where('event_class', CompartmentOpenAuthorized::class)
            ->latest('id')
            ->first();

        $this->assertSame('granted_access', $authorizedEvent->event_properties['authorizationType'] ?? null);
    }

    public function test_accessible_endpoint_includes_compartment_reachable_via_group(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();
        $this->grantGroupAccess($user, $compartment, $admin);

        $response = $this->actingAs($user)->getJson(route('compartments.accessible'));

        $response->assertStatus(200);
        $response->assertJsonFragment(['id' => (string) $compartment->id]);
    }

    public function test_broadcast_recipients_include_group_only_member(): void
    {
        $admin = $this->createAdminUser();
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();
        $this->grantGroupAccess($user, $compartment, $admin);

        $recipients = app(CompartmentStatusBroadcastService::class)
            ->recipientUserIdsForCompartment($compartment);

        $this->assertContains($user->id, $recipients);
    }
}
