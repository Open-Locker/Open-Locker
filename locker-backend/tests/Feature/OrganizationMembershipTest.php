<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\OrganizationMembershipAggregate;
use App\Exceptions\MissingOrganizationContextException;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Auth\WebResetPasswordNotification;
use App\Projectors\OrganizationMembershipProjector;
use App\Services\OrganizationContext;
use App\Services\OrganizationMembershipService;
use App\Services\UserAdministrationService;
use App\StorableEvents\UserAddedToOrganization;
use App\StorableEvents\UserRemovedFromOrganization;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class OrganizationMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_installation_has_exactly_one_default_organization(): void
    {
        $this->assertSame(1, Organization::query()->count());
        $this->assertSame($this->defaultOrganization()->id, app(OrganizationContext::class)->require()->id);
    }

    public function test_the_backfill_adds_every_existing_user_to_the_default_organization(): void
    {
        $users = User::factory()->count(3)->create();

        $migration = require database_path('migrations/2026_10_14_000003_backfill_default_organization_memberships.php');
        $migration->up();
        $migration->up();

        $organization = $this->defaultOrganization();
        $this->assertEqualsCanonicalizing(
            $users->pluck('id')->all(),
            $organization->members()->pluck('users.id')->all(),
        );
        $this->assertSame(3, EloquentStoredEvent::query()
            ->where('event_class', UserAddedToOrganization::class)
            ->count(), 'Running the backfill again must not record duplicate memberships.');
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $organization->id,
            'user_id' => $users->first()?->id,
            'added_by_user_id' => null,
        ]);
    }

    public function test_rolling_the_backfill_back_and_forth_restores_every_membership(): void
    {
        [, $removedMember] = User::factory()->count(2)->create()->all();
        $migration = require database_path('migrations/2026_10_14_000003_backfill_default_organization_memberships.php');
        $migration->up();

        $organizationId = $this->defaultOrganization()->id;
        OrganizationMembershipAggregate::retrieve(OrganizationMembershipAggregate::aggregateUuidFor($organizationId, $removedMember->id))
            ->removeMember($organizationId, $removedMember->id, null, now())
            ->persist();
        $laterMember = User::factory()->create();
        $otherOrganization = Organization::query()->create(['name' => 'Other', 'slug' => 'other']);
        app(OrganizationMembershipService::class)->addMember($this->defaultOrganization(), $laterMember);
        app(OrganizationMembershipService::class)->addMember($otherOrganization, $laterMember);
        $before = $this->membershipRows();

        $migration->down();
        DB::table('organization_user')->delete();
        $migration->up();

        $this->assertSame(1, EloquentStoredEvent::query()
            ->where('event_class', UserRemovedFromOrganization::class)
            ->count(), 'A rollback must not record removals.');
        $this->assertEquals($before, $this->membershipRows(), 'A removed member must stay removed.');
    }

    public function test_a_replay_rebuilds_the_same_membership_table(): void
    {
        $organization = $this->defaultOrganization();
        $memberships = app(OrganizationMembershipService::class);
        $actor = User::factory()->create();
        [$kept, $removed, $deleted] = User::factory()->count(3)->create()->all();

        $this->travelTo(now()->subDay());
        $memberships->addMember($organization, $actor);
        $this->travelBack();
        $memberships->addMember($organization, $kept, $actor->id);
        $memberships->addMember($organization, $removed, $actor->id);
        $memberships->addMember($organization, $deleted, $actor->id);
        OrganizationMembershipAggregate::retrieve(OrganizationMembershipAggregate::aggregateUuidFor($organization->id, $removed->id))
            ->removeMember($organization->id, $removed->id, $actor->id, now())
            ->persist();
        $deleted->delete();
        $deletedOrganization = Organization::query()->create(['name' => 'Gone', 'slug' => 'gone']);
        $memberships->addMember($deletedOrganization, $kept, $actor->id);
        $deletedOrganization->delete();

        $before = $this->membershipRows();
        $this->assertCount(2, $before);

        $this->artisan('event-sourcing:replay', [
            'projector' => [OrganizationMembershipProjector::class],
            '--force' => true,
        ])->assertSuccessful();

        $this->assertEquals($before, $this->membershipRows());
    }

    public function test_creating_a_new_user_adds_them_to_the_context_organization(): void
    {
        Notification::fake();
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('User created'));

        $user = User::query()->where('email', 'ada@example.test')->sole();
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $this->defaultOrganization()->id,
            'user_id' => $user->id,
            'added_by_user_id' => $admin->id,
        ]);
        Notification::assertSentTo($user, WebResetPasswordNotification::class);
    }

    public function test_creating_an_existing_email_adds_that_user_without_changing_their_profile(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $existing = User::factory()->create([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@example.test',
        ]);
        $profile = $existing->only(['first_name', 'last_name', 'password', 'email_verified_at']);

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Someone',
                'last_name' => 'Else',
                'email' => 'grace@example.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('User created'));

        $this->assertSame(1, User::query()->where('email', 'grace@example.test')->count());
        $this->assertEquals($profile, $existing->fresh()?->only(['first_name', 'last_name', 'password', 'email_verified_at']));
        $this->assertDatabaseHas('organization_user', [
            'organization_id' => $this->defaultOrganization()->id,
            'user_id' => $existing->id,
            'added_by_user_id' => $admin->id,
        ]);
        Notification::assertSentTo($existing, WebResetPasswordNotification::class);
    }

    public function test_creating_an_existing_email_keeps_the_password_reset_throttle(): void
    {
        Notification::fake();
        $existing = User::factory()->create(['email' => 'grace@example.test']);
        Password::createToken($existing);

        Livewire::actingAs($this->admin())
            ->test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Someone',
                'last_name' => 'Else',
                'email' => 'grace@example.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(FilamentNotification::make()
                ->title(__('User created'))
                ->body(trans(Password::RESET_THROTTLED))
                ->success());

        Notification::assertNotSentTo($existing, WebResetPasswordNotification::class);
    }

    public function test_creating_a_user_fails_without_an_organization_context(): void
    {
        Organization::query()->delete();

        $this->assertThrows(
            fn () => app(UserAdministrationService::class)->createMember([
                'email' => 'nobody@example.test',
                'first_name' => 'No',
                'last_name' => 'Body',
                'password' => 'irrelevant-password',
            ]),
            MissingOrganizationContextException::class,
        );

        $this->assertDatabaseMissing('users', ['email' => 'nobody@example.test']);
    }

    private function defaultOrganization(): Organization
    {
        return Organization::query()->where('slug', Organization::DEFAULT_SLUG)->sole();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        return $admin;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function membershipRows(): Collection
    {
        return DB::table('organization_user')
            ->orderBy('organization_id')
            ->orderBy('user_id')
            ->get()
            ->map(fn (object $row): array => (array) $row);
    }
}
