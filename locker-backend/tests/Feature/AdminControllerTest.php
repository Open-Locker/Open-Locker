<?php

namespace Tests\Feature;

use App\Models\CompartmentAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class AdminControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private User $adminUser;

    private User $regularUser;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin-Benutzer erstellen
        $this->adminUser = User::factory()->create();
        $this->adminUser->makeAdmin();

        // Regulären Benutzer erstellen
        $this->regularUser = User::factory()->create();

        // Admin-Token erstellen
        $this->token = $this->adminUser->createToken('auth_token')->plainTextToken;
    }

    public function test_non_admin_cannot_access_admin_routes()
    {
        // Token für regulären Benutzer erstellen
        $regularToken = $this->regularUser->createToken('auth_token')->plainTextToken;

        // Versuche, eine Admin-Route aufzurufen
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$regularToken,
        ])->getJson('/api/admin/users');

        // Überprüfen, dass der Zugriff verweigert wird
        $response->assertStatus(403);
    }

    public function test_admin_can_get_all_users()
    {
        // Erstelle ein paar zusätzliche Benutzer
        User::factory()->count(3)->create();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/admin/users');

        $response->assertStatus(200);
        // Die Antwort ist ein JSON-Array ohne 'data'-Schlüssel
        $this->assertCount(5, $response->json()); // Admin + Regular + 3 zusätzliche Benutzer
    }

    public function test_admin_can_make_another_user_admin()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->postJson('/api/admin/users/'.$this->regularUser->id.'/make-admin');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => __('User has been successfully appointed as administrator.'),
        ]);

        // Überprüfen, dass der Benutzer jetzt Admin ist
        $this->regularUser->refresh();
        $this->assertTrue($this->regularUser->isAdmin());
    }

    public function test_admin_cannot_make_existing_admin_to_admin()
    {
        // Mache den regulären Benutzer zum Admin
        $this->regularUser->makeAdmin();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->postJson('/api/admin/users/'.$this->regularUser->id.'/make-admin');

        $response->assertStatus(400);
        $response->assertJsonFragment([
            'message' => __('User is already an administrator.'),
        ]);
    }

    public function test_admin_can_remove_admin_rights()
    {
        // Mache den regulären Benutzer zum Admin
        $this->regularUser->makeAdmin();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->postJson('/api/admin/users/'.$this->regularUser->id.'/remove-admin');

        $response->assertStatus(200);
        $response->assertJsonFragment([
            'message' => __('Administrator rights have been successfully removed.'),
        ]);

        // Überprüfen, dass der Benutzer kein Admin mehr ist
        $this->regularUser->refresh();
        $this->assertFalse($this->regularUser->isAdmin());
    }

    public function test_admin_cannot_remove_admin_from_non_admin()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->postJson('/api/admin/users/'.$this->regularUser->id.'/remove-admin');

        $response->assertStatus(400);
        $response->assertJsonFragment([
            'message' => __('User is not an administrator.'),
        ]);
    }

    public function test_admin_cannot_remove_own_admin_rights()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->postJson('/api/admin/users/'.$this->adminUser->id.'/remove-admin');

        $response->assertStatus(400);
        $response->assertJsonFragment([
            'message' => __('You cannot remove your own administrator rights.'),
        ]);
    }

    public function test_admin_can_get_statistics()
    {
        // Erstelle einige Items und Zugriffsrechte für die Statistiken
        $items = Item::factory()->count(5)->create();

        CompartmentAccess::factory()->count(3)->create([
            'user_id' => $this->regularUser->id,
            'revoked_at' => null,
            'expires_at' => now()->addDay(),
        ]);

        CompartmentAccess::factory()->count(2)->create([
            'user_id' => $this->regularUser->id,
            'revoked_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/admin/statistics');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'statistics' => [
                'total_users',
                'total_items',
                'total_compartment_accesses',
                'active_compartment_accesses',
            ],
        ]);

        $response->assertJson([
            'statistics' => [
                'total_users' => 2, // Admin + Regular
                'total_items' => 5,
                'total_compartment_accesses' => 5,
                'active_compartment_accesses' => 3,
            ],
        ]);
    }

    public function test_admin_can_register_new_user()
    {
        $userData = [
            'name' => $this->faker->name,
            'email' => $this->faker->unique()->safeEmail,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->postJson('/api/admin/users/register', $userData);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => $userData['email'],
        ]);
    }
}
