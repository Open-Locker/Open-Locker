<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\TermsService;
use App\Support\Organizations\DefaultOrganization;
use App\Support\Organizations\OrganizationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Every organization numbers its own terms versions from 1, so "has accepted
 * version 1" means nothing without saying whose. The profile used to compare the
 * number alone and reported another organization's acceptance, while the gate
 * (which compares the version itself) kept refusing.
 */
class TermsOrganizationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $default;

    private Organization $rival;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->default = Organization::query()->where('slug', DefaultOrganization::SLUG)->firstOrFail();
        $this->rival = Organization::create(['name' => 'Rival Operator', 'slug' => 'rival']);

        $this->user = User::factory()->create();
        $this->user->organizations()->syncWithoutDetaching([$this->rival->id => ['joined_at' => now()]]);

        $context = app(OrganizationContext::class);
        $context->runWithin($this->default, fn () => app(TermsService::class)->publishNewVersion('AGB', '<p>Default v1</p>'));
        $context->runWithin($this->rival, function (): void {
            app(TermsService::class)->publishNewVersion('Terms', '<p>Rival v1</p>');
            app(TermsService::class)->acceptCurrentTerms($this->user);
        });
    }

    public function test_accepting_one_organizations_terms_does_not_accept_anothers(): void
    {
        $this->profileIn($this->default)->assertJson([
            'terms_current_version' => 1,
            'terms_last_accepted_version' => null,
            'terms_current_accepted' => false,
        ]);
    }

    public function test_the_organization_whose_terms_were_accepted_reports_them(): void
    {
        $this->profileIn($this->rival)->assertJson([
            'terms_current_version' => 1,
            'terms_last_accepted_version' => 1,
            'terms_current_accepted' => true,
        ]);
    }

    private function profileIn(Organization $organization): TestResponse
    {
        app(OrganizationContext::class)->set(null);
        $token = $this->user->createToken('auth_token')->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Organization' => $organization->id,
        ])->getJson('/api/user')->assertOk();
    }
}
