<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Test the index method returns a successful response.
     */
    public function test_index_returns_successful_response(): void
    {
        Item::factory()->count(3);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/items');

        $response->assertStatus(200);
    }

    /**
     * Test the index method returns the correct structure.
     */
    public function test_index_returns_correct_structure(): void
    {
        Item::factory()->count(3)->create();

        $response = $this->actingAs(User::factory()->create())->getJson('/api/items');

        $response->assertJsonStructure([
            '*' => [
                'id',
                'name',
                'description',
                'image_url',
                'compartment_id',
                'created_at',
                'updated_at',
            ],
        ]);
    }
}
