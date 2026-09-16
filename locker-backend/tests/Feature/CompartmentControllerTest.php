<?php

namespace Tests\Feature;

use App\Models\Compartment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompartmentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_compartments_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/api/compartments');

        $response->assertStatus(401);
    }

    public function test_the_list_reports_each_banks_connection_status(): void
    {
        $user = User::factory()->create();
        $user->makeAdmin();

        $compartment = Compartment::factory()->create();
        $compartment->lockerBank->forceFill([
            'connection_status' => 'offline',
            'connection_status_changed_at' => now(),
        ])->save();

        $this->actingAs($user)->getJson('/api/compartments')
            ->assertStatus(200)
            ->assertJsonPath('locker_banks.0.connection_status', 'offline');
    }

    public function test_a_bank_that_never_reported_is_unknown_rather_than_offline(): void
    {
        $user = User::factory()->create();
        $user->makeAdmin();

        // A fresh bank has never reported: the column defaults to 'unknown', which
        // is not the same as having gone offline, and the app colours the two
        // differently.
        Compartment::factory()->create();

        $this->actingAs($user)->getJson('/api/compartments')
            ->assertStatus(200)
            ->assertJsonPath('locker_banks.0.connection_status', 'unknown');
    }

    public function test_compartments_endpoint_returns_compartments_with_contents(): void
    {
        // Sees every compartment because they are an admin, which this test used to
        // get implicitly from being the first user created.
        $user = User::factory()->create();
        $user->makeAdmin();

        /** @var Compartment $firstCompartment */
        $firstCompartment = Compartment::factory()->create();

        /** @var Compartment $secondCompartment */
        $secondCompartment = Compartment::factory()->create([
            'locker_bank_id' => $firstCompartment->locker_bank_id,
        ]);

        $response = $this->actingAs($user)->getJson('/api/compartments');

        $response->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonCount(1, 'locker_banks')
            ->assertJsonCount(2, 'locker_banks.0.compartments');

        $response->assertJsonStructure([
            'locker_banks' => [[
                'id',
                'name',
                'location_description',
                'connection_status',
                'compartments' => [[
                    'id',
                    'number',
                    'content_note',
                ]],
            ]],
        ]);

        $response->assertJsonMissingPath('locker_banks.0.compartments.0.item');

        $response->assertJsonFragment([
            'id' => (string) $secondCompartment->id,
            'content_note' => null,
        ]);
    }
}
