<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Compartment;
use App\Models\LockerBank;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserRole;
use App\Projectors\CompartmentAccessProjector;
use App\Services\CompartmentAccessService;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\Projectionist;
use Tests\TestCase;

/**
 * A replay runs with no organization in context. Projectors that read
 * organization-scoped models must take the organization from the event, or a
 * rebuild inserts rows the organization constraint refuses and finds nothing
 * to update.
 */
class ProjectorReplayOrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_replay_rebuilds_an_organizations_access_in_that_organization(): void
    {
        $rival = Organization::create(['name' => 'Rival Operator', 'slug' => 'rival']);
        $user = User::factory()->create();
        $admin = User::factory()->create();
        UserRole::create([
            'user_id' => $admin->id,
            'organization_id' => $rival->id,
            'role' => Role::Admin->value,
            'granted_at' => now(),
        ]);

        app(OrganizationContext::class)->runWithin($rival, function () use ($user, $admin): void {
            $compartment = Compartment::factory()->for(LockerBank::factory())->create();
            $access = app(CompartmentAccessService::class);
            $access->grantAccess($user, $compartment, actor: $admin);
            $access->revokeAccess($user, $compartment, actor: $admin);
        });

        DB::table('compartment_accesses')->delete();
        app(OrganizationContext::class)->set(null);

        app(Projectionist::class)->replay(collect([app(CompartmentAccessProjector::class)]));

        $rebuilt = DB::table('compartment_accesses')->where('user_id', $user->id)->sole();
        $this->assertSame($rival->id, $rebuilt->organization_id);
        $this->assertNotNull($rebuilt->revoked_at, 'The revocation must replay onto the rebuilt row.');
    }
}
