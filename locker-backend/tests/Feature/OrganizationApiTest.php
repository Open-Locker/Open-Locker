<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ResolveOrganization;
use App\Models\Compartment;
use App\Models\CompartmentAccess;
use App\Models\LockerBank;
use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The server's half of the switcher: a client says where it is acting, and the
 * server decides what that statement is allowed to mean.
 */
class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_that_sends_no_header_keeps_working_when_the_user_has_one_organization(): void
    {
        $user = $this->memberOf([$this->organization('alpha')]);
        Sanctum::actingAs($user);

        // Every app in the field today sends no header. A single-organization
        // installation must never notice the feature exists.
        $this->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_several_memberships_and_no_header_starts_in_one_of_them(): void
    {
        $alpha = $this->organization('alpha');
        $beta = $this->organization('beta');
        $user = $this->memberOf([$beta, $alpha]);
        Sanctum::actingAs($user);

        // Signing in is not a decision. Every organization here is one this
        // person already belongs to, so there is nothing to protect against by
        // refusing — the server starts them in the first by name and the app
        // offers a switch once they are inside.
        $this->getJson('/api/compartments/accessible')->assertOk();

        $this->assertSame($alpha->id, app(OrganizationContext::class)->currentId());
    }

    public function test_a_header_naming_an_organization_the_user_does_not_belong_to_is_refused(): void
    {
        $user = $this->memberOf([$this->organization('alpha')]);
        $stranger = $this->organization('beta');
        Sanctum::actingAs($user);

        $this->withHeader(ResolveOrganization::HEADER, $stranger->id)
            ->getJson('/api/compartments/accessible')
            ->assertStatus(403)
            ->assertJsonPath('code', 'organization_forbidden');
    }

    public function test_the_switcher_lists_only_the_users_own_organizations(): void
    {
        $user = $this->memberOf([$this->organization('alpha'), $this->organization('beta')]);
        $this->organization('gamma');
        Sanctum::actingAs($user);

        $this->withHeader(ResolveOrganization::HEADER, $user->organizations()->first()->id)
            ->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_the_switcher_can_load_its_list_in_the_state_that_opens_it(): void
    {
        $user = $this->memberOf([$this->organization('alpha'), $this->organization('beta')]);
        Sanctum::actingAs($user);

        // No header, several memberships: exactly the state the app is in when
        // a refusal sends it to the switcher. If this endpoint needed an
        // organization resolved first, the only screen that can end the refusal
        // would be the one screen the refusal blocks.
        $this->getJson('/api/organizations')
            ->assertOk()
            ->assertJsonCount(2);
    }

    public function test_a_compartment_is_resolvable_from_its_url_in_the_acting_organization(): void
    {
        $alpha = $this->organization('alpha');
        $user = $this->memberOf([$alpha]);

        $compartment = app(OrganizationContext::class)->runWithin($alpha, function () {
            $bank = LockerBank::create(['name' => 'Alpha Bank']);

            return Compartment::create([
                'locker_bank_id' => $bank->id,
                'number' => 1,
                'slave_id' => 1,
                'address' => 0,
            ]);
        });

        app(OrganizationContext::class)->runWithin($alpha, function () use ($user, $compartment) {
            CompartmentAccess::create([
                'user_id' => $user->id,
                'compartment_id' => $compartment->id,
                'granted_at' => now(),
            ]);
        });

        app(OrganizationContext::class)->set(null);
        Sanctum::actingAs($user);

        // Route model binding runs in the `api` group, before route middleware.
        // Without explicit priority the compartment is resolved before anything
        // says which organization the request acts in, the scope matches
        // nothing, and a locker the user owns comes back as "no query results".
        $this->withHeader(ResolveOrganization::HEADER, $alpha->id)
            ->postJson("/api/compartments/{$compartment->id}/open")
            ->assertStatus(202);
    }

    private function organization(string $slug): Organization
    {
        return Organization::create(['name' => ucfirst($slug).' Operator', 'slug' => $slug]);
    }

    /** @param list<Organization> $organizations */
    private function memberOf(array $organizations): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        // The factory places new users in the default organization; these tests
        // are about which organizations a user belongs to, so they state it.
        $user->organizations()->detach();

        foreach ($organizations as $organization) {
            $user->organizations()->attach($organization->id, ['joined_at' => now()]);
        }

        return $user;
    }
}
