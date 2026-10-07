<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Compartment;
use App\Models\Group;
use App\Models\LockerBank;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserRole;
use App\Services\GroupAccessService;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The spike's central claim: one operator cannot reach another's data, and the
 * database refuses the crossing even when the application forgets to.
 */
class OrganizationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $alpha;

    private Organization $beta;

    private LockerBank $alphaBank;

    private LockerBank $betaBank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = Organization::create(['name' => 'Alpha Operator', 'slug' => 'alpha']);
        $this->beta = Organization::create(['name' => 'Beta Operator', 'slug' => 'beta']);

        $this->alphaBank = $this->makeBank($this->alpha, 'Alpha Bank');
        $this->betaBank = $this->makeBank($this->beta, 'Beta Bank');
    }

    public function test_a_manager_sees_only_their_own_organizations_locker_banks(): void
    {
        $manager = $this->memberOf($this->alpha, Role::Manager);

        $this->within($this->alpha, function () use ($manager): void {
            $this->assertTrue($manager->hasRole(Role::Manager->value));
            $this->assertEqualsCanonicalizing(
                ['Alpha Bank'],
                LockerBank::query()->pluck('name')->all(),
            );
        });
    }

    public function test_a_manager_in_one_organization_is_an_ordinary_user_in_another(): void
    {
        $manager = $this->memberOf($this->alpha, Role::Manager);

        $this->within($this->beta, function () use ($manager): void {
            $manager->flushPermissionCache();

            $this->assertFalse($manager->hasRole(Role::Manager->value));
            $this->assertFalse($manager->hasPermission(Permission::CompartmentOpen));

            // The scope follows the organization in context, not the viewer:
            // keeping a non-member out of this context at all is the
            // middleware's job, and the capability check above is what makes
            // being here useless to them.
            $this->assertSame(['Beta Bank'], LockerBank::query()->pluck('name')->all());
        });
    }

    public function test_another_organizations_compartment_cannot_be_resolved_by_id(): void
    {
        $betaCompartment = $this->within($this->beta, fn (): Compartment => Compartment::create([
            'locker_bank_id' => $this->betaBank->id,
            'number' => 1,
            'slave_id' => 1,
            'address' => 1,
        ]));

        // Route model binding resolves a compartment straight from its UUID, so
        // this is the path an attacker takes rather than the listing screens.
        $this->within($this->alpha, function () use ($betaCompartment): void {
            $this->assertNull(Compartment::query()->find($betaCompartment->id));
        });
    }

    public function test_acting_without_an_organization_grants_nothing(): void
    {
        $manager = $this->memberOf($this->alpha, Role::Manager);

        app(OrganizationContext::class)->set(null);
        $manager->flushPermissionCache();

        // Fail closed: work that never said which organization it meant gets no
        // rows and no capabilities, rather than everything.
        $this->assertSame([], $manager->roleNames());
        $this->assertSame([], LockerBank::query()->pluck('name')->all());
    }

    public function test_the_database_refuses_a_compartment_pointing_at_another_organizations_bank(): void
    {
        // Dev and production run PostgreSQL; this suite runs SQLite in memory,
        // which cannot ADD CONSTRAINT, so the constraint is not present here to
        // be tested. Verified by hand against PostgreSQL, and the reason the
        // spike flags the test driver as a decision the team has to make.
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('Composite foreign keys require PostgreSQL; the suite runs SQLite.');
        }

        $this->expectException(QueryException::class);

        // Deliberately bypassing the model so no global scope or observer can
        // intervene: this asserts the constraint, not the application.
        Compartment::query()->getQuery()->insert([
            'id' => (string) Str::uuid(),
            'locker_bank_id' => $this->betaBank->id,
            'organization_id' => $this->alpha->id,
            'number' => 99,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_service_refuses_a_group_grant_to_another_organizations_compartment(): void
    {
        $admin = $this->memberOf($this->alpha, Role::Admin);
        $group = $this->within(
            $this->alpha,
            fn (): Group => app(GroupAccessService::class)->createGroup('Alpha Team', actor: $admin),
        );
        $betaCompartment = $this->within($this->beta, fn (): Compartment => Compartment::create([
            'locker_bank_id' => $this->betaBank->id,
            'number' => 1,
            'slave_id' => 1,
            'address' => 1,
        ]));

        $this->expectException(AuthorizationException::class);

        $this->within(
            $this->alpha,
            fn () => app(GroupAccessService::class)->grantCompartmentAccess(
                $group,
                $betaCompartment,
                actor: $admin,
            ),
        );
    }

    public function test_the_database_refuses_a_group_grant_to_another_organizations_compartment(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('Composite foreign keys require PostgreSQL; the suite runs SQLite.');
        }

        $admin = $this->memberOf($this->alpha, Role::Admin);
        $group = $this->within(
            $this->alpha,
            fn (): Group => app(GroupAccessService::class)->createGroup('Alpha Team', actor: $admin),
        );
        $betaCompartment = $this->within($this->beta, fn (): Compartment => Compartment::create([
            'locker_bank_id' => $this->betaBank->id,
            'number' => 1,
            'slave_id' => 1,
            'address' => 1,
        ]));

        $this->expectException(QueryException::class);

        DB::table('group_compartment_accesses')->insert([
            'group_id' => $group->id,
            'compartment_id' => $betaCompartment->id,
            'organization_id' => $this->alpha->id,
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_platform_admin_holds_no_organization_role_but_may_still_act(): void
    {
        $platformAdmin = User::factory()->create();
        UserRole::create([
            'user_id' => $platformAdmin->id,
            'organization_id' => null,
            'role' => Role::PlatformAdmin->value,
            'granted_at' => now(),
        ]);

        $this->within($this->alpha, function () use ($platformAdmin): void {
            // Not a member, so it holds no organization role...
            $this->assertSame([], $platformAdmin->roleNames());
            // ...but the installation-level role still answers the gate.
            $this->assertTrue($platformAdmin->isPlatformAdmin());
            $this->assertTrue($platformAdmin->can(Permission::CompartmentOpen->value));
        });
    }

    private function makeBank(Organization $organization, string $name): LockerBank
    {
        return $this->within($organization, fn (): LockerBank => LockerBank::create(['name' => $name]));
    }

    private function memberOf(Organization $organization, Role $role): User
    {
        $user = User::factory()->create();
        $user->organizations()->attach($organization->id, ['joined_at' => now()]);

        UserRole::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'role' => $role->value,
            'granted_at' => now(),
        ]);

        return $user;
    }

    private function within(Organization $organization, callable $callback): mixed
    {
        return app(OrganizationContext::class)->runWithin($organization, $callback);
    }
}
