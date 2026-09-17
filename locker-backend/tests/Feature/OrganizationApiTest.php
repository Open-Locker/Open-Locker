<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ResolveOrganization;
use App\Models\Organization;
use App\Models\User;
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

    public function test_several_memberships_and_no_header_is_refused_rather_than_guessed(): void
    {
        $user = $this->memberOf([$this->organization('alpha'), $this->organization('beta')]);
        Sanctum::actingAs($user);

        $this->getJson('/api/compartments/accessible')
            ->assertStatus(409)
            ->assertJsonPath('code', 'organization_not_selected');
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

    private function organization(string $slug): Organization
    {
        return Organization::create(['name' => ucfirst($slug).' Operator', 'slug' => $slug]);
    }

    /** @param list<Organization> $organizations */
    private function memberOf(array $organizations): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        foreach ($organizations as $organization) {
            $user->organizations()->attach($organization->id, ['joined_at' => now()]);
        }

        return $user;
    }
}
