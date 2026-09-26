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
use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\LockerBank;
use App\Models\Organization;
use App\Models\TermsDocument;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\Organizations\RemovedFromOrganizationNotification;
use App\Services\CompartmentAccessService;
use App\Services\GroupAccessService;
use App\Services\UserAdministrationService;
use App\Support\EventSourcing\OrganizationStamp;
use App\Support\Organizations\DefaultOrganization;
use App\Support\Organizations\OrganizationContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
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

        $default = Organization::query()->where('slug', DefaultOrganization::SLUG)->firstOrFail();

        $single = User::factory()->create();
        UserRole::create([
            'user_id' => $single->id,
            'organization_id' => $default->id,
            'role' => Role::Manager->value,
            'granted_at' => now(),
        ]);

        $this->actingAs($single);
        $this->assertFalse(Filament::getCurrentOrDefaultPanel()->hasTenantMenu());

        $second = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $single->organizations()->attach($second->id, ['joined_at' => now()]);

        // A membership alone still is not something to switch to: with no role
        // there, the second organization is a panel with every resource hidden.
        $single->flushPermissionCache();
        $this->assertFalse(Filament::getCurrentOrDefaultPanel()->hasTenantMenu());

        UserRole::create([
            'user_id' => $single->id,
            'organization_id' => $second->id,
            'role' => Role::Manager->value,
            'granted_at' => now(),
        ]);

        // The concept appears only once it means something: two operators the
        // person can actually work in.
        $single->flushPermissionCache();
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

    public function test_an_organization_admin_can_reach_the_panel_on_a_real_request(): void
    {
        $organization = Organization::query()->where('slug', DefaultOrganization::SLUG)->firstOrFail();
        $admin = User::factory()->create(['email_verified_at' => now()]);
        UserRole::create([
            'user_id' => $admin->id,
            'organization_id' => $organization->id,
            'role' => Role::Admin->value,
            'granted_at' => now(),
        ]);

        // Undo this suite's convenience: a real request arrives with neither an
        // organization nor a tenant, and Filament asks whether the panel may be
        // entered during authentication — before the tenant middleware runs.
        // Pre-seeding both is what hid this.
        app(OrganizationContext::class)->set(null);
        Filament::setTenant(null, isQuiet: true);

        $response = $this->actingAs($admin)->get('/admin/'.$organization->slug);

        // The panel has no dashboard: its root redirects to the first
        // navigation item. A redirect rather than a 403 is what proves the
        // admin got through the door.
        $response->assertRedirect();
        $this->assertStringNotContainsString(
            'login',
            (string) $response->headers->get('Location'),
        );
    }

    public function test_the_same_role_can_be_held_in_two_organizations(): void
    {
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $user = User::factory()->create();

        // The case the whole membership model exists for: a manager for one
        // operator, something else entirely for another.
        foreach ([$alpha, $beta] as $organization) {
            UserRole::create([
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'role' => Role::Manager->value,
                'granted_at' => now(),
            ]);
        }

        $this->assertTrue($this->within($alpha, fn (): bool => $user->hasRole(Role::Manager->value)));

        $user->flushPermissionCache();
        $this->assertTrue($this->within($beta, fn (): bool => $user->hasRole(Role::Manager->value)));
    }

    public function test_roles_are_not_cached_across_organizations(): void
    {
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $user = User::factory()->create();

        UserRole::create([
            'user_id' => $user->id,
            'organization_id' => $alpha->id,
            'role' => Role::Manager->value,
            'granted_at' => now(),
        ]);

        // Reusing one instance across organizations is what a command walking
        // all of them does; a cache that is not keyed by organization answers
        // the second with the first one's roles.
        $this->assertTrue($this->within($alpha, fn (): bool => $user->hasRole(Role::Manager->value)));
        $this->assertFalse($this->within($beta, fn (): bool => $user->hasRole(Role::Manager->value)));
    }

    public function test_an_organization_admin_cannot_mint_a_platform_admin(): void
    {
        $organization = Organization::query()->where('slug', DefaultOrganization::SLUG)->firstOrFail();

        $orgAdmin = User::factory()->create();
        UserRole::create([
            'user_id' => $orgAdmin->id,
            'organization_id' => $organization->id,
            'role' => Role::Admin->value,
            'granted_at' => now(),
        ]);

        $target = User::factory()->create();

        // Administering your own operator must not be a route to administering
        // everyone else's. The escalation is silent otherwise: the projector
        // stores the role with no organization, the check constraint is happy,
        // and the new platform admin can read and write inside every
        // organization.
        $this->expectException(AuthorizationException::class);

        app(UserAdministrationService::class)->changeRole($orgAdmin, $target, Role::PlatformAdmin);
    }

    public function test_a_platform_admin_may_appoint_another(): void
    {
        // The last-admin guard rolls back any role change that would leave the
        // installation with no administrator, so one has to exist first.
        $organization = Organization::query()->where('slug', DefaultOrganization::SLUG)->firstOrFail();
        $existingAdmin = User::factory()->create();
        UserRole::create([
            'user_id' => $existingAdmin->id,
            'organization_id' => $organization->id,
            'role' => Role::Admin->value,
            'granted_at' => now(),
        ]);

        $platformAdmin = User::factory()->create();
        UserRole::create([
            'user_id' => $platformAdmin->id,
            'organization_id' => null,
            'role' => Role::PlatformAdmin->value,
            'granted_at' => now(),
        ]);

        $target = User::factory()->create();

        app(UserAdministrationService::class)->changeRole($platformAdmin, $target, Role::PlatformAdmin);

        $target->flushPermissionCache();
        $this->assertTrue($target->isPlatformAdmin());
    }

    public function test_an_organization_cannot_be_left_without_an_administrator(): void
    {
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);

        $alphaAdmin = $this->adminOf($alpha);
        $this->adminOf($beta);

        // Beta still has an administrator, so an installation-wide count stays
        // above zero while Alpha is left with nobody able to administer it —
        // recoverable only by a platform admin.
        $changed = $this->within(
            $alpha,
            fn (): bool => app(UserAdministrationService::class)->changeRole($alphaAdmin, $alphaAdmin, Role::User),
        );

        $this->assertFalse($changed, 'Demoting the last admin of an organization must be refused.');

        $alphaAdmin->flushPermissionCache();
        $this->assertTrue($this->within($alpha, fn (): bool => $alphaAdmin->isAdmin()));
    }

    /**
     * Granted through the aggregate rather than by writing the read model:
     * revoking only works when the events exist, so a directly-inserted row
     * makes the revoke a silent no-op and the test proves nothing.
     */
    private function adminOf(Organization $organization): User
    {
        $admin = User::factory()->create();

        $this->within($organization, fn () => UserRoleAggregate::retrieve(
            UserRoleAggregate::aggregateUuidFor($admin->id)
        )->grantRole($admin->id, Role::Admin->value, null, now(), $organization->id)->persist());

        return $admin;
    }

    public function test_a_membership_list_never_names_another_operator(): void
    {
        config()->set('organizations.multi_organization', true);

        $alpha = Organization::create(['name' => 'Alpha Operator', 'slug' => 'alpha']);
        $beta = Organization::create(['name' => 'Beta Operator', 'slug' => 'beta']);

        $shared = User::factory()->create();
        $shared->organizations()->detach();
        $shared->organizations()->attach([
            $alpha->id => ['joined_at' => now()],
            $beta->id => ['joined_at' => now()],
        ]);

        $admin = User::factory()->create();
        UserRole::create([
            'user_id' => $admin->id,
            'organization_id' => $alpha->id,
            'role' => Role::Admin->value,
            'granted_at' => now(),
        ]);

        // Rendered rather than queried: filtering the query inside the test
        // would prove only that the test filters, and the relation manager's
        // own scoping would never run.
        $this->within($alpha, function () use ($admin, $shared): void {
            Livewire::actingAs($admin)
                ->test(OrganizationsRelationManager::class, [
                    'ownerRecord' => $shared,
                    'pageClass' => EditUser::class,
                ])
                ->assertSee('Alpha Operator')
                ->assertDontSee('Beta Operator');
        });
    }

    public function test_an_admin_cannot_act_on_a_person_who_also_belongs_elsewhere(): void
    {
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);

        $alphaAdmin = User::factory()->create();
        UserRole::create([
            'user_id' => $alphaAdmin->id,
            'organization_id' => $alpha->id,
            'role' => Role::Admin->value,
            'granted_at' => now(),
        ]);

        $shared = User::factory()->create();
        $shared->organizations()->detach();
        $shared->organizations()->attach([
            $alpha->id => ['joined_at' => now()],
            $beta->id => ['joined_at' => now()],
        ]);

        // Managing a user here is identity-level: it changes their email, sends
        // a password reset to the new address, and can delete the account. The
        // person is Beta's user too, and Beta's own last-admin guard cannot see
        // an action taken from Alpha.
        $this->within($alpha, function () use ($alphaAdmin, $shared): void {
            $this->assertFalse(
                app(UserAdministrationService::class)->canManageUser($alphaAdmin, $shared),
            );
        });
    }

    public function test_an_admin_may_still_act_on_a_person_of_their_own_organization_only(): void
    {
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);

        $alphaAdmin = User::factory()->create();
        UserRole::create([
            'user_id' => $alphaAdmin->id,
            'organization_id' => $alpha->id,
            'role' => Role::Admin->value,
            'granted_at' => now(),
        ]);

        $ours = User::factory()->create();
        $ours->organizations()->detach();
        $ours->organizations()->attach($alpha->id, ['joined_at' => now()]);

        $this->within($alpha, function () use ($alphaAdmin, $ours): void {
            $this->assertTrue(
                app(UserAdministrationService::class)->canManageUser($alphaAdmin, $ours),
            );
        });
    }

    public function test_an_organization_admin_cannot_act_on_a_platform_admin(): void
    {
        // A platform admin who is also a member of just this organization looks
        // like one of its own people, but a password reset or deletion would
        // hand the organization admin the whole installation.
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $alphaAdmin = $this->adminOf($alpha);
        $platformAdmin = $this->memberOfOnly($alpha);
        UserRole::create([
            'user_id' => $platformAdmin->id,
            'organization_id' => null,
            'role' => Role::PlatformAdmin->value,
            'granted_at' => now(),
        ]);

        $this->within($alpha, function () use ($alphaAdmin, $platformAdmin): void {
            $this->assertFalse(
                app(UserAdministrationService::class)->canManageUser($alphaAdmin, $platformAdmin),
            );
        });
    }

    public function test_an_organization_admin_cannot_change_a_platform_admins_role(): void
    {
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $alphaAdmin = $this->adminOf($alpha);
        $platformAdmin = $this->memberOfOnly($alpha);
        UserRole::create([
            'user_id' => $platformAdmin->id,
            'organization_id' => null,
            'role' => Role::PlatformAdmin->value,
            'granted_at' => now(),
        ]);

        $this->expectException(AuthorizationException::class);

        $this->within($alpha, fn () => app(UserAdministrationService::class)->changeRole($alphaAdmin, $platformAdmin, Role::Manager));
    }

    public function test_deleting_someone_who_belongs_elsewhere_only_removes_them_here(): void
    {
        Notification::fake();
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $alphaAdmin = $this->adminOf($alpha);
        $shared = $this->memberOfOnly($alpha);
        $shared->organizations()->attach($beta->id, ['joined_at' => now()]);

        [$compartment, $group] = $this->within($alpha, function () use ($alphaAdmin, $shared): array {
            $compartment = Compartment::factory()->for(LockerBank::factory())->create();
            app(CompartmentAccessService::class)->grantAccess($shared, $compartment, actor: $alphaAdmin);
            $group = app(GroupAccessService::class)->createGroup('Staff', actor: $alphaAdmin);
            app(GroupAccessService::class)->addUser($group, $shared, actor: $alphaAdmin);

            return [$compartment, $group];
        });

        $deleted = $this->within($alpha, fn (): bool => app(UserAdministrationService::class)->deleteUser($alphaAdmin, $shared));

        $this->assertTrue($deleted);
        $this->assertNotNull(User::query()->find($shared->id), 'Another organization still depends on the account.');
        $this->assertSame([$beta->id], $shared->organizations()->pluck('organizations.id')->all());
        $this->assertNotNull(
            CompartmentAccess::withoutGlobalScope('organization')
                ->where('user_id', $shared->id)->where('compartment_id', $compartment->id)->value('revoked_at'),
            'Access left behind would still route door updates to the person.',
        );
        $this->assertNotNull(
            DB::table('group_user')->where('user_id', $shared->id)->where('group_id', $group->id)->value('revoked_at'),
        );
        Notification::assertSentTo($shared, RemovedFromOrganizationNotification::class);
    }

    public function test_the_last_admin_cannot_be_removed_by_deleting_them(): void
    {
        // Their only way here: the sole admin, who also belongs elsewhere,
        // deletes themselves, which would only remove them from this one.
        Notification::fake();
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $onlyAdmin = $this->adminOf($alpha);
        $onlyAdmin->organizations()->syncWithoutDetaching([
            $alpha->id => ['joined_at' => now()],
            $beta->id => ['joined_at' => now()],
        ]);

        $deleted = $this->within($alpha, fn (): bool => app(UserAdministrationService::class)->deleteUser($onlyAdmin, $onlyAdmin));

        $this->assertFalse($deleted);
        $this->assertTrue($onlyAdmin->organizations()->whereKey($alpha->id)->exists());
        $this->assertTrue(
            UserRole::query()->where('user_id', $onlyAdmin->id)->where('organization_id', $alpha->id)->where('role', Role::Admin->value)->exists(),
        );
        Notification::assertNotSentTo($onlyAdmin, RemovedFromOrganizationNotification::class);
    }

    public function test_deleting_someone_who_belongs_only_here_deletes_the_account(): void
    {
        Notification::fake();
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $alphaAdmin = $this->adminOf($alpha);
        $ours = $this->memberOfOnly($alpha);

        $this->within($alpha, fn (): bool => app(UserAdministrationService::class)->deleteUser($alphaAdmin, $ours));

        $this->assertNull(User::query()->find($ours->id));
        // Nobody is left to write to.
        Notification::assertNotSentTo($ours, RemovedFromOrganizationNotification::class);
    }

    public function test_the_delete_button_is_offered_for_someone_who_belongs_elsewhere(): void
    {
        // Hiding it would tell the admin that the person belongs to another
        // organization, which is exactly what they must not learn.
        $alpha = Organization::create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Organization::create(['name' => 'Beta', 'slug' => 'beta']);
        $alphaAdmin = $this->adminOf($alpha);
        $shared = $this->memberOfOnly($alpha);
        $shared->organizations()->attach($beta->id, ['joined_at' => now()]);

        $this->within($alpha, function () use ($alphaAdmin, $shared): void {
            $this->assertTrue(app(UserAdministrationService::class)->canDeleteUser($alphaAdmin, $shared));
        });
    }

    public function test_the_last_platform_admin_cannot_be_deleted(): void
    {
        $platformAdmin = $this->platformAdmin();

        $deleted = app(UserAdministrationService::class)->deleteUser($platformAdmin, $platformAdmin);

        $this->assertFalse($deleted);
        $this->assertNotNull(User::query()->find($platformAdmin->id));
    }

    public function test_a_platform_admin_can_be_deleted_while_another_remains(): void
    {
        $platformAdmin = $this->platformAdmin();
        $another = $this->platformAdmin();

        $deleted = app(UserAdministrationService::class)->deleteUser($platformAdmin, $another);

        $this->assertTrue($deleted);
        $this->assertNull(User::query()->find($another->id));
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create();
        UserRole::create([
            'user_id' => $user->id,
            'organization_id' => null,
            'role' => Role::PlatformAdmin->value,
            'granted_at' => now(),
        ]);

        return $user;
    }

    private function within(Organization $organization, callable $callback): mixed
    {
        return app(OrganizationContext::class)->runWithin($organization, $callback);
    }
}
