<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Console\Commands\DetectOfflineLockers;
use App\Enums\Role;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\OrganizationResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\OrganizationsRelationManager;
use App\Models\LockerBank;
use App\Models\Organization;
use App\Models\TermsDocument;
use App\Models\User;
use App\Models\UserRole;
use App\Support\EventSourcing\OrganizationStamp;
use App\Support\Organizations\OrganizationContext;
use Filament\Facades\Filament;
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

    public function test_the_audit_log_shows_only_the_current_organizations_history(): void
    {
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);

        // Real domain events, one in each organization: a role grant is
        // event-sourced and on the audit whitelist.
        foreach ([$alpha, $beta] as $organization) {
            $member = User::factory()->create();
            $this->within($organization, fn () => UserRoleAggregate::retrieve(
                UserRoleAggregate::aggregateUuidFor($member->id)
            )->grantRole($member->id, Role::Manager->value, null, now(), $organization->id)->persist());
        }

        // The event store is shared, so the audit log cannot be tenant-scoped
        // the way an owned table is: rows are filtered by the organization each
        // event stamped into its metadata when it happened.
        $alphaEvents = $this->within(
            $alpha,
            fn () => AuditLogResource::getEloquentQuery()->pluck('meta_data'),
        );

        $this->assertNotEmpty($alphaEvents);

        foreach ($alphaEvents as $metaData) {
            $this->assertSame($alpha->id, $metaData[OrganizationStamp::KEY] ?? null);
        }
    }

    public function test_membership_management_is_hidden_on_a_single_organization_installation(): void
    {
        config()->set('organizations.multi_organization', false);
        $this->assertFalse(OrganizationsRelationManager::canViewForRecord(
            User::factory()->create(),
            EditUser::class,
        ));

        config()->set('organizations.multi_organization', true);
        $this->assertTrue(OrganizationsRelationManager::canViewForRecord(
            User::factory()->create(),
            EditUser::class,
        ));
    }

    public function test_a_member_of_one_organization_is_never_shown_the_switcher(): void
    {
        config()->set('organizations.multi_organization', true);

        $single = User::factory()->create();
        $this->actingAs($single);
        $this->assertFalse(Filament::getCurrentOrDefaultPanel()->hasTenantMenu());

        $second = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $single->organizations()->attach($second->id, ['joined_at' => now()]);

        // The concept appears only once it means something: two operators to
        // move between.
        $this->assertTrue(Filament::getCurrentOrDefaultPanel()->hasTenantMenu());
    }

    public function test_user_pickers_never_offer_another_organizations_people(): void
    {
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);

        $ours = $this->memberOfOnly($alpha);
        $theirs = $this->memberOfOnly($beta);

        // A user row is a global identity, so nothing scopes it automatically.
        // Every picker has to say so — offering another operator's staff is how
        // they end up in a group here, and group membership confers compartment
        // access.
        $offered = $this->within($alpha, fn (): array => User::query()
            ->inCurrentOrganization()
            ->pluck('id')
            ->all());

        $this->assertContains($ours->id, $offered);
        $this->assertNotContains($theirs->id, $offered);
    }

    private function memberOfOnly(Organization $organization): User
    {
        $user = User::factory()->create();
        $user->organizations()->detach();
        $user->organizations()->attach($organization->id, ['joined_at' => now()]);

        return $user;
    }

    private function within(Organization $organization, callable $callback): mixed
    {
        return app(OrganizationContext::class)->runWithin($organization, $callback);
    }
}
