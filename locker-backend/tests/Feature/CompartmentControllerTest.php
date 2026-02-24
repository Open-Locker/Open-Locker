<?php

namespace Tests\Feature;

use App\Models\Compartment;
use App\Models\Item;
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

    public function test_compartments_endpoint_returns_compartments_with_contents(): void
    {
        $user = User::factory()->create();

        /** @var Compartment $compartmentWithItem */
        $compartmentWithItem = Compartment::factory()->create();
        Item::factory()->create([
            'compartment_id' => $compartmentWithItem->id,
        ]);

        /** @var Compartment $emptyCompartment */
        $emptyCompartment = Compartment::factory()->create([
            'locker_bank_id' => $compartmentWithItem->locker_bank_id,
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
                'compartments' => [[
                    'id',
                    'number',
                    'item',
                ]],
            ]],
        ]);

        $response->assertJsonFragment([
            'id' => (string) $emptyCompartment->id,
            'item' => null,
        ]);
    }
}
