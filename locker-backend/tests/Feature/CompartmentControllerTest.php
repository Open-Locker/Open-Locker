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

    public function test_compartments_endpoint_returns_compartments_with_contents(): void
    {
        $user = User::factory()->create();

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
