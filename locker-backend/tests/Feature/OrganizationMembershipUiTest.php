<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\RelationManagers\OrganizationsRelationManager;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserRole;
use App\Notifications\Auth\WebResetPasswordNotification;
use App\Notifications\Organizations\AddedToOrganizationNotification;
use App\StorableEvents\UserJoinedOrganization;
use App\Support\Organizations\DefaultOrganization;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * Driving the membership screen rather than the model underneath it.
 *
 * The relation and its guards can each be right while the screen is broken:
 * Filament resolves the inverse of User::organizations() by convention when
 * attaching, so a relation named for the domain instead of the related model
 * left the attach action calling a method that did not exist. Nothing short of
 * exercising the action catches that.
 */
class OrganizationMembershipUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('organizations.multi_organization', true);
    }

    public function test_a_platform_admin_can_attach_a_user_to_another_organization(): void
    {
        Notification::fake();
        $default = Organization::query()->where('slug', DefaultOrganization::SLUG)->firstOrFail();
        $other = Organization::create(['name' => 'Rival Operator', 'slug' => 'rival']);

        $platformAdmin = User::factory()->create();
        UserRole::create([
            'user_id' => $platformAdmin->id,
            'organization_id' => null,
            'role' => Role::PlatformAdmin->value,
            'granted_at' => now(),
        ]);

        $target = User::factory()->create();

        Livewire::actingAs($platformAdmin)
            ->test(OrganizationsRelationManager::class, [
                'ownerRecord' => $target,
                'pageClass' => EditUser::class,
            ])
            ->callAction(TestAction::make('attach')->table(), data: [
                'recordId' => $other->id,
                'role' => Role::Manager->value,
            ])
            ->assertHasNoActionErrors();

        $this->assertTrue(
            $target->organizations()->whereKey($other->id)->exists(),
            'Attaching a membership through the panel must actually create it.',
        );
        $this->assertTrue($target->organizations()->whereKey($default->id)->exists());

        // The role is granted in the organization just joined, and nowhere
        // else: adding someone to an operator must not change what they are
        // anywhere they already belonged.
        $this->assertTrue(
            UserRole::query()
                ->where('user_id', $target->id)
                ->where('organization_id', $other->id)
                ->where('role', Role::Manager->value)
                ->exists(),
        );
        $this->assertFalse(
            UserRole::query()
                ->where('user_id', $target->id)
                ->where('organization_id', $default->id)
                ->exists(),
        );

        // The person was added without being asked, so they are told who did it.
        Notification::assertSentTo($target, AddedToOrganizationNotification::class);
    }

    public function test_creating_a_user_whose_email_exists_elsewhere_adds_that_account(): void
    {
        Notification::fake();
        $other = Organization::create(['name' => 'Rival Operator', 'slug' => 'rival']);
        $stranger = $this->givenMemberOf($other, 'shared@example.test');
        $admin = $this->givenDefaultOrganizationAdmin();
        $usersBefore = User::query()->count();

        // Different case and stray spaces still name the same person.
        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Someone',
                'last_name' => 'Else',
                'email' => ' Shared@Example.test ',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame($usersBefore, User::query()->count(), 'No second account may be created.');
        $this->assertTrue($stranger->organizations()->whereKey($this->defaultOrganization()->id)->exists());
        $this->assertSame(
            ['Shared', 'Person'],
            [$stranger->fresh()->first_name, $stranger->fresh()->last_name],
            'Another organization may depend on the name, so it is never overwritten.',
        );
        Notification::assertSentTo($stranger, AddedToOrganizationNotification::class);
        Notification::assertNotSentTo($stranger, WebResetPasswordNotification::class);
        $this->assertTrue(
            EloquentStoredEvent::query()
                ->where('event_class', UserJoinedOrganization::class)
                ->where('event_properties->userId', $stranger->id)
                ->where('event_properties->actorUserId', $admin->id)
                ->where('event_properties->existingAccount', true)
                ->exists(),
        );
    }

    public function test_creating_a_user_who_is_already_a_member_is_refused(): void
    {
        $this->givenMemberOf($this->defaultOrganization(), 'member@example.test');
        $admin = $this->givenDefaultOrganizationAdmin();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Member',
                'last_name' => 'Again',
                'email' => 'MEMBER@example.test',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_creating_a_user_with_a_new_email_creates_the_account(): void
    {
        Notification::fake();
        $admin = $this->givenDefaultOrganizationAdmin();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'first_name' => 'New',
                'last_name' => 'Person',
                'email' => 'new@example.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'new@example.test')->sole();
        $this->assertTrue($user->organizations()->whereKey($this->defaultOrganization()->id)->exists());
        Notification::assertSentTo($user, WebResetPasswordNotification::class);
        Notification::assertNotSentTo($user, AddedToOrganizationNotification::class);
        $this->assertTrue(
            EloquentStoredEvent::query()
                ->where('event_class', UserJoinedOrganization::class)
                ->where('event_properties->userId', $user->id)
                ->where('event_properties->existingAccount', false)
                ->exists(),
        );
    }

    public function test_attaching_without_a_role_leaves_an_ordinary_member(): void
    {
        $other = Organization::create(['name' => 'Rival Operator', 'slug' => 'rival']);

        $platformAdmin = User::factory()->create();
        UserRole::create([
            'user_id' => $platformAdmin->id,
            'organization_id' => null,
            'role' => Role::PlatformAdmin->value,
            'granted_at' => now(),
        ]);

        $target = User::factory()->create();

        Livewire::actingAs($platformAdmin)
            ->test(OrganizationsRelationManager::class, [
                'ownerRecord' => $target,
                'pageClass' => EditUser::class,
            ])
            ->callAction(TestAction::make('attach')->table(), data: [
                'recordId' => $other->id,
                'role' => Role::User->value,
            ])
            ->assertHasNoActionErrors();

        // A member with no role is a real state, not a broken half-one: an end
        // user belongs to an operator and never touches the panel.
        $this->assertTrue($target->organizations()->whereKey($other->id)->exists());
        $this->assertSame(0, UserRole::query()->where('user_id', $target->id)->count());
    }

    public function test_removing_a_person_revokes_their_role_there_for_good(): void
    {
        $other = Organization::create(['name' => 'Rival Operator', 'slug' => 'rival']);
        $platformAdmin = $this->givenPlatformAdmin();
        $target = User::factory()->create();
        $manager = fn () => $this->relationManagerFor($platformAdmin, $target);

        $manager()->callAction(TestAction::make('attach')->table(), data: [
            'recordId' => $other->id,
            'role' => Role::Manager->value,
        ]);
        $manager()->callAction(TestAction::make('detach')->table($other));
        // Re-adding as an ordinary member must not bring the manager role back.
        $manager()->callAction(TestAction::make('attach')->table(), data: [
            'recordId' => $other->id,
            'role' => Role::User->value,
        ]);

        $this->assertTrue($target->organizations()->whereKey($other->id)->exists());
        $this->assertFalse(
            UserRole::query()->where('user_id', $target->id)->where('organization_id', $other->id)->exists(),
        );
    }

    public function test_the_last_admin_cannot_be_removed(): void
    {
        $other = Organization::create(['name' => 'Rival Operator', 'slug' => 'rival']);
        $platformAdmin = $this->givenPlatformAdmin();
        $onlyAdmin = User::factory()->create();

        $this->relationManagerFor($platformAdmin, $onlyAdmin)
            ->callAction(TestAction::make('attach')->table(), data: [
                'recordId' => $other->id,
                'role' => Role::Admin->value,
            ]);
        $this->relationManagerFor($platformAdmin, $onlyAdmin)
            ->callAction(TestAction::make('detach')->table($other));

        $this->assertTrue($onlyAdmin->organizations()->whereKey($other->id)->exists());
        $this->assertTrue(
            UserRole::query()
                ->where('user_id', $onlyAdmin->id)
                ->where('organization_id', $other->id)
                ->where('role', Role::Admin->value)
                ->exists(),
        );
    }

    private function givenPlatformAdmin(): User
    {
        $platformAdmin = User::factory()->create();
        UserRole::create([
            'user_id' => $platformAdmin->id,
            'organization_id' => null,
            'role' => Role::PlatformAdmin->value,
            'granted_at' => now(),
        ]);

        return $platformAdmin;
    }

    private function relationManagerFor(User $actor, User $owner): Testable
    {
        return Livewire::actingAs($actor)->test(OrganizationsRelationManager::class, [
            'ownerRecord' => $owner,
            'pageClass' => EditUser::class,
        ]);
    }

    private function defaultOrganization(): Organization
    {
        return Organization::query()->where('slug', DefaultOrganization::SLUG)->firstOrFail();
    }

    private function givenMemberOf(Organization $organization, string $email): User
    {
        $user = User::factory()->create(['first_name' => 'Shared', 'last_name' => 'Person', 'email' => $email]);
        $user->organizations()->sync([$organization->id => ['joined_at' => now()]]);

        return $user;
    }

    private function givenDefaultOrganizationAdmin(): User
    {
        $admin = User::factory()->create();
        UserRole::create([
            'user_id' => $admin->id,
            'organization_id' => $this->defaultOrganization()->id,
            'role' => Role::Admin->value,
            'granted_at' => now(),
        ]);

        return $admin;
    }
}
