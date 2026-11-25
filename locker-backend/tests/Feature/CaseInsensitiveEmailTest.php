<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use App\Notifications\Auth\WebResetPasswordNotification;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Email addresses identify accounts regardless of casing (#371).
 */
class CaseInsensitiveEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_emails_are_stored_lowercase(): void
    {
        $user = User::factory()->create(['email' => 'Ada.Lovelace@Example.TEST']);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'ada.lovelace@example.test']);
    }

    public function test_find_by_email_ignores_email_casing(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);

        $this->assertTrue($user->is(User::findByEmail('Ada@Example.TEST')));
        $this->assertNull(User::findByEmail('someone@example.test'));
    }

    public function test_api_login_ignores_email_casing(): void
    {
        User::factory()->create(['email' => 'ada@example.test', 'password' => bcrypt('password123')]);

        $this->postJson('/api/login', ['email' => 'Ada@Example.test', 'password' => 'password123'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    public function test_admin_panel_login_ignores_email_casing(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.test', 'password' => bcrypt('password123')]);
        $admin->makeAdmin();
        Filament::setCurrentPanel('admin');

        Livewire::test(Login::class)
            ->fillForm(['email' => 'ADMIN@example.test', 'password' => 'password123'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_password_reset_link_ignores_email_casing(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.test']);

        $this->postJson(route('password.email'), ['email' => 'ADA@example.test'])->assertOk();

        Notification::assertSentTo($user, WebResetPasswordNotification::class);
    }

    public function test_password_reset_completes_with_email_in_other_casing(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.test']);
        $token = Password::createToken($user);

        $this->postJson(route('password.store'), [
            'email' => 'ADA@Example.test',
            'token' => $token,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password123', $user->fresh()->password));
    }

    public function test_profile_update_rejects_another_accounts_email_in_other_casing(): void
    {
        $user = User::factory()->create();
        User::factory()->create(['email' => 'taken@example.test']);

        $this->actingAs($user)
            ->putJson('/api/profile', [
                'first_name' => 'Updated',
                'last_name' => 'Name',
                'email' => 'Taken@Example.test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_creating_a_user_with_an_existing_email_in_other_casing_reuses_the_account(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $admin->makeAdmin();
        $existing = User::factory()->create(['email' => 'grace@example.test']);

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Someone',
                'last_name' => 'Else',
                'email' => 'Grace@Example.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, User::query()->where('email', 'grace@example.test')->count());
        $this->assertDatabaseHas('organization_user', ['user_id' => $existing->id]);
    }

    public function test_editing_a_user_rejects_another_accounts_email_in_other_casing(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();
        User::factory()->create(['email' => 'taken@example.test']);
        $target = User::factory()->create(['email' => 'target@example.test']);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'first_name' => $target->first_name,
                'last_name' => $target->last_name,
                'email' => 'Taken@Example.test',
            ])
            ->call('save')
            ->assertHasFormErrors(['email' => 'unique']);

        $this->assertSame('target@example.test', $target->fresh()->email);
    }

    public function test_editing_a_user_accepts_their_own_email_in_other_casing(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();
        $target = User::factory()->create(['email' => 'target@example.test']);

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'first_name' => $target->first_name,
                'last_name' => $target->last_name,
                'email' => 'Target@Example.test',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('target@example.test', $target->fresh()->email);
    }

    public function test_migration_lowercases_existing_emails(): void
    {
        $id = $this->insertRawUser('Mixed@Example.test');

        $this->lowercaseMigration()->up();

        $this->assertDatabaseHas('users', ['id' => $id, 'email' => 'mixed@example.test']);
    }

    public function test_migration_stops_when_accounts_differ_only_in_casing(): void
    {
        $this->insertRawUser('twin@example.test');
        $this->insertRawUser('Twin@example.test');

        $this->assertThrows(
            fn () => $this->lowercaseMigration()->up(),
            RuntimeException::class,
            'twin@example.test',
        );
        $this->assertDatabaseHas('users', ['email' => 'Twin@example.test']);
    }

    /**
     * Bypasses the model mutator to recreate data stored before #371.
     */
    private function insertRawUser(string $email): int
    {
        return DB::table('users')->insertGetId([
            'first_name' => 'Legacy',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'irrelevant',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lowercaseMigration(): object
    {
        return require database_path('migrations/2026_11_19_000001_lowercase_user_emails.php');
    }
}
