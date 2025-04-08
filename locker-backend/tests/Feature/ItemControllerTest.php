<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemLoan;
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
                'locker_id',
                'borrowed_at',
                'created_at',
                'updated_at',
            ],
        ]);
    }

    public function test_user_can_borrow_item()
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $response = $this->actingAs($user)->postJson(route('items.borrow', $item->id));

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => __('Item borrowed successfully'),
            ]);

        $this->assertDatabaseHas('item_loans', [
            'item_id' => $item->id,
            'user_id' => $user->id,
            'returned_at' => null,
        ]);
    }

    public function test_user_can_return_item()
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $loan = ItemLoan::factory()->create([
            'item_id' => $item->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->postJson(route('items.return', $item->id));

        $response->assertStatus(200)
            ->assertJson([
                'status' => true,
                'message' => __('Item returned successfully'),
            ]);

        $this->assertDatabaseHas('item_loans', [
            'id' => $loan->id,
            'item_id' => $item->id,
            'user_id' => $user->id,
        ]);
        $this->assertNotNull($loan->fresh()->returned_at);
    }

    public function test_user_cannot_borrow_item_already_borrowed()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $item = Item::factory()->create();

        ItemLoan::factory()->create([
            'item_id' => $item->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($user)->postJson(route('items.borrow', $item->id));

        $response->assertStatus(200)
            ->assertJson([
                'status' => false,
                'message' => __('Item is already borrowed'),
            ]);
    }

    public function test_user_cannot_return_item_not_borrowed()
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $response = $this->actingAs($user)->postJson(route('items.return', $item->id));

        $response->assertStatus(200)
            ->assertJson([
                'status' => false,
                'message' => __('Item is not borrowed'),
            ]);
    }

    public function test_get_borrowed_items_from_user_returns_correct_items()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $borrowedItems = Item::factory()->count(3)->create();
        foreach ($borrowedItems as $item) {
            ItemLoan::factory()->create([
                'item_id' => $item->id,
                'user_id' => $user->id,
            ]);
        }

        $borrowedByOtherItems = Item::factory()->count(2)->create();
        foreach ($borrowedByOtherItems as $item) {
            ItemLoan::factory()->create([
                'item_id' => $item->id,
                'user_id' => $otherUser->id,
            ]);
        }

        Item::factory()->count(2)->create();

        $response = $this->actingAs($user)->getJson(route('items.borrowed'));

        $response->assertStatus(200)
            ->assertJsonCount(3)
            ->assertJsonStructure([
                '*' => ['id', 'name', 'description', 'image_url', 'locker_id', 'borrowed_at'],
            ]);

        foreach ($borrowedItems as $item) {
            $response->assertJsonFragment([
                'id' => $item->id,
                'borrowed_at' => $item->activeLoan->borrowed_at->toISOString(),
            ]);
        }
    }

    public function test_returned_items_are_not_shown_in_borrowed_items()
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $loan = ItemLoan::factory()->create([
            'item_id' => $item->id,
            'user_id' => $user->id,
            'returned_at' => now(),
        ]);

        $response = $this->actingAs($user)->getJson(route('items.borrowed'));

        $response->assertStatus(200)
            ->assertJsonCount(0);
    }

    public function test_user_can_view_loan_history()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $borrowedItems = Item::factory(2)->create();
        $returnedItems = Item::factory(2)->create();

        // Create loans for the test user
        foreach ($borrowedItems as $item) {
            ItemLoan::factory()->create([
                'item_id' => $item->id,
                'user_id' => $user->id,
            ]);
        }

        foreach ($returnedItems as $item) {
            ItemLoan::factory()->create([
                'item_id' => $item->id,
                'user_id' => $user->id,
                'returned_at' => now(),
            ]);
        }

        // Create loans for other user
        $otherUserItems = Item::factory(2)->create();
        foreach ($otherUserItems as $item) {
            ItemLoan::factory()->create([
                'item_id' => $item->id,
                'user_id' => $otherUser->id,
            ]);
        }

        $response = $this->actingAs($user)->getJson(route('items.loanHistory'));

        $response->assertStatus(200)
            ->assertJsonCount(4)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'item' => [
                        'id',
                        'name',
                        'description',
                        'image_url',
                        'locker_id',
                        'borrowed_at',
                        'created_at',
                        'updated_at',
                    ],
                    'user_id',
                    'borrowed_at',
                    'returned_at',
                ],
            ]);

        // Überprüfe, ob die Ausleihen des Benutzers in der Antwort enthalten sind
        foreach ($borrowedItems as $index => $item) {
            $response->assertJsonPath($index.'.item.id', $item->id);
            $response->assertJsonPath($index.'.user_id', $user->id);
        }

        foreach ($returnedItems as $index => $item) {
            $response->assertJsonPath(($index + 2).'.item.id', $item->id);
            $response->assertJsonPath(($index + 2).'.user_id', $user->id);
        }
    }
}
