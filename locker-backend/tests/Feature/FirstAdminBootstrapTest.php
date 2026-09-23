<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Organizations\DefaultOrganization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The very first administrator on a fresh instance.
 *
 * Nothing is signed in and there is no request, so there is no organization in
 * context — and everything downstream is scoped fail-closed. An admin who
 * belongs to no organization can sign in and then reach nothing, which is the
 * worst possible first impression of an installation.
 */
class FirstAdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh instance has no session and no tenant.
        app(OrganizationContext::class)->set(null);
        DefaultOrganization::forget();
    }

    public function test_an_installation_can_be_given_a_platform_administrator(): void
    {
        $this->artisan('first-admin:create', ['email' => 'admin@example.test'])->assertSuccessful();
        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

        // Without a way in from outside, multi-organization support is a dead
        // end: only a platform admin may create organizations or appoint
        // another platform admin, and the panel offers no way to become the
        // first one. Reaching the server is the authorization.
        $this->artisan('platform-admin:grant', ['email' => 'admin@example.test'])
            ->assertSuccessful();

        $admin->flushPermissionCache();
        $this->assertTrue($admin->isPlatformAdmin());
    }

    public function test_the_first_administrator_lands_in_the_default_organization(): void
    {
        $this->artisan('first-admin:create', ['email' => 'admin@example.test'])
            ->assertSuccessful();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $default = Organization::query()->where('slug', DefaultOrganization::SLUG)->firstOrFail();

        $this->assertTrue(
            $admin->organizations()->whereKey($default->id)->exists(),
            'The first administrator must belong to the default organization, or the panel has no tenant to open.',
        );
    }

    public function test_their_admin_role_is_held_inside_that_organization(): void
    {
        $this->artisan('first-admin:create', ['email' => 'admin@example.test'])
            ->assertSuccessful();

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();

        // A role row with no organization would violate the check constraint on
        // PostgreSQL, since only platform_admin may hold one.
        $role = UserRole::query()
            ->where('user_id', $admin->id)
            ->where('role', Role::Admin->value)
            ->firstOrFail();

        $this->assertSame(DefaultOrganization::id(), $role->organization_id);
    }
}
