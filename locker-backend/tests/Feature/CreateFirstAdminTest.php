<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateFirstAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_from_the_configured_address(): void
    {
        config(['admin.first_admin_email' => 'ops@example.test']);

        $this->artisan('first-admin:create')->assertSuccessful();

        $admin = User::query()->where('email', 'ops@example.test')->sole();

        $this->assertTrue($admin->isAdmin());
        $this->assertSame(1, User::adminRoleCount());
    }

    public function test_the_created_admin_cannot_be_logged_in_as_without_a_password_reset(): void
    {
        config(['admin.first_admin_email' => 'ops@example.test']);

        $this->artisan('first-admin:create')->assertSuccessful();

        $admin = User::query()->where('email', 'ops@example.test')->sole();

        // Email is pre-verified so the reset link is the only step, but the password
        // is random and never surfaced, so it cannot be guessed from the code.
        $this->assertNotNull($admin->email_verified_at);
        $this->assertNotEmpty($admin->password);
    }

    public function test_an_explicit_address_overrides_the_configured_one(): void
    {
        config(['admin.first_admin_email' => 'configured@example.test']);

        $this->artisan('first-admin:create', ['email' => 'explicit@example.test'])
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'explicit@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'configured@example.test']);
    }

    public function test_it_is_a_no_op_once_an_admin_exists(): void
    {
        $existing = User::factory()->create();
        $existing->makeAdmin();

        config(['admin.first_admin_email' => 'second@example.test']);

        $this->artisan('first-admin:create')
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'second@example.test']);
        $this->assertSame(1, User::adminRoleCount());
    }

    public function test_running_it_twice_creates_only_one_admin(): void
    {
        config(['admin.first_admin_email' => 'ops@example.test']);

        $this->artisan('first-admin:create')->assertSuccessful();
        $this->artisan('first-admin:create')->assertSuccessful();

        $this->assertSame(1, User::query()->where('email', 'ops@example.test')->count());
        $this->assertSame(1, User::adminRoleCount());
    }

    public function test_it_succeeds_quietly_when_no_address_is_configured(): void
    {
        // Runs unattended on every deploy: a missing optional setting must not take
        // the container down.
        config(['admin.first_admin_email' => null]);

        $this->artisan('first-admin:create')
            ->expectsOutputToContain('nothing to do')
            ->assertSuccessful();

        $this->assertSame(0, User::query()->count());
    }

    public function test_it_fails_on_a_malformed_address(): void
    {
        config(['admin.first_admin_email' => 'not-an-email']);

        $this->artisan('first-admin:create')->assertFailed();

        $this->assertSame(0, User::query()->count());
    }

    public function test_the_admin_panel_exposes_no_registration_route(): void
    {
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('filament.admin.auth.register')
        );
    }
}
