<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\Item;
use App\Models\LockerBank;
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

    private function createAdminUser(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    public function test_user_with_permanent_access_can_open_compartment(): void
    {
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($user, $compartment, actor: $admin);

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
        $admin = $this->createAdminUser();
        $allowedCompartment = Compartment::factory()->create();
        $blockedCompartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($user, $allowedCompartment, actor: $admin);

        $this->mock(LockerService::class, function ($mock): void {
            $mock->shouldNotReceive('openCompartment');
        });

        $response = $this->actingAs($user)->postJson(route('compartments.open', $blockedCompartment->id));

        $response->assertStatus(403);
    }

    public function test_duplicate_grant_events_keep_single_access_row(): void
    {
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        $service = app(CompartmentAccessService::class);
        $service->grantAccess($user, $compartment, actor: $admin);
        $service->grantAccess($user, $compartment, actor: $admin);

        $this->assertDatabaseCount('compartment_accesses', 1);
    }

    public function test_non_admin_cannot_grant_access(): void
    {
        $actor = $this->createRegularUser();
        $targetUser = $this->createRegularUser();
        $compartment = Compartment::factory()->create();

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        app(CompartmentAccessService::class)->grantAccess(
            user: $targetUser,
            compartment: $compartment,
            actor: $actor
        );
    }

    public function test_non_admin_cannot_revoke_access(): void
    {
        $nonAdmin = $this->createRegularUser();
        $targetUser = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        $service = app(CompartmentAccessService::class);
        $service->grantAccess($targetUser, $compartment, actor: $admin);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $service->revokeAccess(
            user: $targetUser,
            compartment: $compartment,
            actor: $nonAdmin
        );
    }

    public function test_revoked_access_blocks_opening_immediately(): void
    {
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        $service = app(CompartmentAccessService::class);
        $service->grantAccess($user, $compartment, actor: $admin);
        $service->revokeAccess($user, $compartment, actor: $admin);

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
        $admin = $this->createAdminUser();
        $compartment = Compartment::factory()->create();

        app(CompartmentAccessService::class)->grantAccess($owner, $compartment, actor: $admin);

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

    public function test_accessible_compartments_returns_only_user_access_grouped_by_locker_bank(): void
    {
        $user = $this->createRegularUser();
        $admin = $this->createAdminUser();

        $lockerA = LockerBank::factory()->create(['name' => 'Alpha Locker']);
        $lockerB = LockerBank::factory()->create(['name' => 'Beta Locker']);

        $allowedCompartmentA = Compartment::factory()->create([
            'locker_bank_id' => $lockerA->id,
            'number' => 1,
        ]);
        $blockedCompartmentA = Compartment::factory()->create([
            'locker_bank_id' => $lockerA->id,
            'number' => 2,
        ]);
        $allowedCompartmentB = Compartment::factory()->create([
            'locker_bank_id' => $lockerB->id,
            'number' => 3,
        ]);

        Item::query()->create([
            'name' => 'Test Item',
            'description' => 'Assigned to allowed compartment',
            'image_path' => null,
            'compartment_id' => $allowedCompartmentA->id,
        ]);

        $service = app(CompartmentAccessService::class);
        $service->grantAccess($user, $allowedCompartmentA, actor: $admin);
        $service->grantAccess($user, $allowedCompartmentB, actor: $admin);
        $service->grantAccess($user, $blockedCompartmentA, expiresAt: now()->subMinute(), actor: $admin);

        $response = $this->actingAs($user)->getJson(route('compartments.accessible'));

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonCount(2, 'locker_banks');

        $this->assertCount(2, $response->json('locker_banks'));
        $this->assertSame('Alpha Locker', $response->json('locker_banks.0.name'));
        $this->assertSame('Beta Locker', $response->json('locker_banks.1.name'));
        $this->assertCount(1, $response->json('locker_banks.0.compartments'));
        $this->assertCount(1, $response->json('locker_banks.1.compartments'));
        $this->assertSame((string) $allowedCompartmentA->id, $response->json('locker_banks.0.compartments.0.id'));
        $this->assertSame((string) $allowedCompartmentB->id, $response->json('locker_banks.1.compartments.0.id'));
        $this->assertSame('Test Item', $response->json('locker_banks.0.compartments.0.item.name'));
    }

    public function test_accessible_compartments_returns_all_compartments_for_admin(): void
    {
        $admin = $this->createAdminUser();
        $locker = LockerBank::factory()->create(['name' => 'Gamma Locker']);
        $compartmentOne = Compartment::factory()->create([
            'locker_bank_id' => $locker->id,
            'number' => 1,
        ]);
        $compartmentTwo = Compartment::factory()->create([
            'locker_bank_id' => $locker->id,
            'number' => 2,
        ]);

        $response = $this->actingAs($admin)->getJson(route('compartments.accessible'));

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonCount(1, 'locker_banks')
            ->assertJsonCount(2, 'locker_banks.0.compartments');

        $returnedIds = collect($response->json('locker_banks.0.compartments'))
            ->pluck('id')
            ->all();

        $this->assertContains((string) $compartmentOne->id, $returnedIds);
        $this->assertContains((string) $compartmentTwo->id, $returnedIds);
    }
}
