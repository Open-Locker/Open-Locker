<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\DetectOfflineLockers;
use App\Enums\Role;
use App\Filament\Resources\OrganizationResource;
use App\Models\LockerBank;
use App\Models\Organization;
use App\Models\TermsDocument;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The boundary as the rest of the product meets it: terms, scheduled work, and
 * who is even shown that organizations exist.
 */
class OrganizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_organization_has_its_own_terms(): void
    {
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);

        $this->within($alpha, fn () => TermsDocument::create(['name' => 'Alpha AGB']));
        $this->within($beta, fn () => TermsDocument::create(['name' => 'Beta AGB']));

        // One document each, and never the other's: a user acting in Alpha is
        // asked to accept Alpha's terms, not whichever was created first.
        $this->assertSame(['Alpha AGB'], $this->within($alpha, fn () => TermsDocument::query()->pluck('name')->all()));
        $this->assertSame(['Beta AGB'], $this->within($beta, fn () => TermsDocument::query()->pluck('name')->all()));
    }

    public function test_scheduled_maintenance_reaches_every_organization(): void
    {
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);

        foreach ([$alpha, $beta] as $organization) {
            $this->within($organization, fn () => LockerBank::create([
                'name' => $organization->name.' Bank',
                'last_heartbeat_at' => now()->subHour(),
                'heartbeat_timeout_seconds' => 60,
                'connection_status' => 'online',
            ]));
        }

        // Locker banks are scoped fail-closed, so a command that never says
        // which organization it means finds nothing and reports success. This
        // one walks them explicitly.
        $this->artisan(DetectOfflineLockers::class)
            ->expectsOutputToContain('Detected 2 offline locker(s).')
            ->assertSuccessful();
    }

    public function test_managing_organizations_is_hidden_unless_the_installation_hosts_several(): void
    {
        $platformAdmin = User::factory()->create();
        UserRole::create([
            'user_id' => $platformAdmin->id,
            'organization_id' => null,
            'role' => Role::PlatformAdmin->value,
            'granted_at' => now(),
        ]);
        $this->actingAs($platformAdmin);

        config()->set('organizations.multi_organization', false);
        $this->assertFalse(OrganizationResource::canViewAny());

        config()->set('organizations.multi_organization', true);
        $this->assertTrue(OrganizationResource::canViewAny());
    }

    public function test_an_organization_admin_is_never_shown_organization_management(): void
    {
        config()->set('organizations.multi_organization', true);

        $admin = User::factory()->create();
        $organization = $admin->organizations()->first();
        UserRole::create([
            'user_id' => $admin->id,
            'organization_id' => $organization->id,
            'role' => Role::Admin->value,
            'granted_at' => now(),
        ]);
        $this->actingAs($admin);

        // Administering one operator is a different job from administering the
        // installation, and the panel says so by not offering it.
        $this->assertFalse(OrganizationResource::canViewAny());
    }

    private function within(Organization $organization, callable $callback): mixed
    {
        return app(OrganizationContext::class)->runWithin($organization, $callback);
    }
}
