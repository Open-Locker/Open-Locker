<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\User;
use App\Services\CompartmentAccessService;
use App\Services\LockerService;
use App\StorableEvents\CompartmentAccessGranted;
use App\StorableEvents\CompartmentOpenAuthorized;
use App\StorableEvents\CompartmentOpenDenied;
use App\StorableEvents\CompartmentOpenRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class CompartmentAccessControllerTest extends TestCase
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

    public function test_user_with_permanent_access_can_open_compartment(): void
    {
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($user, $compartment);

        $this->mock(LockerService::class, function ($mock) use ($compartment): void {
            $mock->shouldReceive('openCompartment')
                ->once()
                ->withArgs(function (Compartment $model, ?string $requestId) use ($compartment): bool {
                    return (string) $model->id === (string) $compartment->id
                        && is_string($requestId)
                        && $requestId !== '';
                });
        });

        $response = $this->actingAs($user)->postJson(route('compartments.open', $compartment->id));

        $response->assertStatus(202)
            ->assertJsonPath('status', true)
            ->assertJsonPath('state', 'pending')
            ->assertJsonPath('message', __('Compartment open request accepted'));
        $this->assertNotEmpty($response->json('command_id'));

        $statusResponse = $this->actingAs($user)->getJson(route('compartments.open-status', [
            'commandId' => $response->json('command_id'),
        ]));
        $statusResponse->assertStatus(200)
            ->assertJsonPath('command_id', $response->json('command_id'))
            ->assertJsonPath('state', 'accepted');

        $this->assertDatabaseHas('compartment_accesses', [
            'user_id' => $user->id,
            'compartment_id' => (string) $compartment->id,
            'revoked_at' => null,
        ]);

        $this->assertDatabaseHas('stored_events', [
            'event_class' => CompartmentAccessGranted::class,
        ]);
    }

    public function test_user_without_access_gets_forbidden(): void
    {
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $this->mock(LockerService::class, function ($mock): void {
            $mock->shouldNotReceive('openCompartment');
        });

        $response = $this->actingAs($user)->postJson(route('compartments.open', $compartment->id));

        $response->assertStatus(403)
            ->assertJson([
                'status' => false,
                'state' => 'denied',
                'message' => __('You do not have access to this compartment'),
            ]);
        $this->assertNotEmpty($response->json('command_id'));

        $this->assertDatabaseHas('stored_events', [
            'event_class' => CompartmentOpenRequested::class,
        ]);
        $this->assertDatabaseHas('stored_events', [
            'event_class' => CompartmentOpenDenied::class,
        ]);
    }

    public function test_user_with_expired_access_gets_forbidden(): void
    {
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        CompartmentAccess::factory()->create([
            'user_id' => $user->id,
            'compartment_id' => $compartment->id,
            'expires_at' => now()->subMinute(),
        ]);

        $this->mock(LockerService::class, function ($mock): void {
            $mock->shouldNotReceive('openCompartment');
        });

        $response = $this->actingAs($user)->postJson(route('compartments.open', $compartment->id));

        $response->assertStatus(403);
    }

    public function test_user_with_revoked_access_gets_forbidden(): void
    {
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        CompartmentAccess::factory()->create([
            'user_id' => $user->id,
            'compartment_id' => $compartment->id,
            'expires_at' => now()->addDay(),
            'revoked_at' => now(),
        ]);

        $this->mock(LockerService::class, function ($mock): void {
            $mock->shouldNotReceive('openCompartment');
        });

        $response = $this->actingAs($user)->postJson(route('compartments.open', $compartment->id));

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_gets_unauthorized(): void
    {
        $compartment = Compartment::factory()->create();

        $response = $this->postJson(route('compartments.open', $compartment->id));

        $response->assertStatus(401);
    }

    public function test_opening_unknown_compartment_returns_not_found(): void
    {
        $user = $this->createRegularUser();

        $response = $this->actingAs($user)->postJson(route('compartments.open', [
            'compartment' => '00000000-0000-0000-0000-000000000000',
        ]));

        $response->assertStatus(404);
    }

    public function test_user_access_to_other_compartment_does_not_grant_global_access(): void
    {
        $user = $this->createRegularUser();
        $allowedCompartment = Compartment::factory()->create();
        $blockedCompartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($user, $allowedCompartment);

        $this->mock(LockerService::class, function ($mock): void {
            $mock->shouldNotReceive('openCompartment');
        });

        $response = $this->actingAs($user)->postJson(route('compartments.open', $blockedCompartment->id));

        $response->assertStatus(403);
    }

    public function test_duplicate_grant_events_keep_single_access_row(): void
    {
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $service = app(CompartmentAccessService::class);
        $service->grantAccess($user, $compartment);
        $service->grantAccess($user, $compartment);

        $this->assertDatabaseCount('compartment_accesses', 1);
    }

    public function test_revoked_access_blocks_opening_immediately(): void
    {
        $user = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $service = app(CompartmentAccessService::class);
        $service->grantAccess($user, $compartment);
        $service->revokeAccess($user, $compartment);

        $this->mock(LockerService::class, function ($mock): void {
            $mock->shouldNotReceive('openCompartment');
        });

        $response = $this->actingAs($user)->postJson(route('compartments.open', $compartment->id));

        $response->assertStatus(403);
    }

    public function test_admin_can_open_without_explicit_access(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();
        $compartment = Compartment::factory()->create();

        $this->mock(LockerService::class, function ($mock) use ($compartment): void {
            $mock->shouldReceive('openCompartment')
                ->once()
                ->withArgs(function (Compartment $model, ?string $requestId) use ($compartment): bool {
                    return (string) $model->id === (string) $compartment->id
                        && is_string($requestId)
                        && $requestId !== '';
                });
        });

        $response = $this->actingAs($admin)->postJson(route('compartments.open', $compartment->id));

        $response->assertStatus(202);
        $this->assertNotEmpty($response->json('command_id'));

        /** @var ?EloquentStoredEvent $authorizedEvent */
        $authorizedEvent = EloquentStoredEvent::query()
            ->where('event_class', CompartmentOpenAuthorized::class)
            ->latest('id')
            ->first();

        $this->assertNotNull($authorizedEvent);
        $this->assertSame('admin_override', $authorizedEvent->event_properties['authorizationType'] ?? null);
    }

    public function test_open_status_endpoint_returns_not_found_for_unknown_command(): void
    {
        $user = $this->createRegularUser();

        $response = $this->actingAs($user)->getJson(route('compartments.open-status', [
            'commandId' => '00000000-0000-0000-0000-000000000000',
        ]));

        $response->assertStatus(404)
            ->assertJsonPath('status', false);
    }

    public function test_user_cannot_read_other_users_open_status(): void
    {
        $owner = $this->createRegularUser();
        $otherUser = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($owner, $compartment);

        $this->mock(LockerService::class, function ($mock): void {
            $mock->shouldReceive('openCompartment')->once();
        });

        $openResponse = $this->actingAs($owner)->postJson(route('compartments.open', $compartment->id));
        $commandId = $openResponse->json('command_id');

        $statusResponse = $this->actingAs($otherUser)->getJson(route('compartments.open-status', [
            'commandId' => $commandId,
        ]));

        $statusResponse->assertStatus(403)
            ->assertJsonPath('status', false);
    }
}
