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
use App\Support\Organizations\DefaultOrganization;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
    }

    public function test_creating_a_user_whose_email_belongs_to_another_organization_fails_cleanly(): void
    {
        $other = Organization::create(['name' => 'Rival Operator', 'slug' => 'rival']);

        $stranger = User::factory()->create(['email' => 'shared@example.test']);
        $stranger->organizations()->detach();
        $stranger->organizations()->attach($other->id, ['joined_at' => now()]);

        $admin = User::factory()->create();
        UserRole::create([
            'user_id' => $admin->id,
            'organization_id' => Organization::query()->where('slug', DefaultOrganization::SLUG)->value('id'),
            'role' => Role::Admin->value,
            'granted_at' => now(),
        ]);

        // The admin cannot see this person — they belong to another operator —
        // so "create" is the only route they are offered. Without a validation
        // rule that collided with the database's unique index and surfaced as a
        // server error.
        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'first_name' => 'Shared',
                'last_name' => 'Person',
                'email' => 'shared@example.test',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
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
}
