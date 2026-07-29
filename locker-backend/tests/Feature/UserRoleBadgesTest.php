<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserRoleBadgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_labels_resolve_localized_names_and_default_to_user(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        $manager = User::factory()->create();
        UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($manager->id))
            ->grantRole($manager->id, Role::Manager->value, $admin->id, now())
            ->persist();

        $plainUser = User::factory()->create();

        $this->assertSame([__('Administrator')], UserResource::roleLabels($admin->fresh()));
        $this->assertSame([__('Manager')], UserResource::roleLabels($manager->fresh()));
        $this->assertSame([__('User')], UserResource::roleLabels($plainUser));
    }

    public function test_users_table_shows_role_badges_instead_of_admin_column(): void
    {
        $admin = User::factory()->create();
        $admin->makeAdmin();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertTableColumnExists('roles')
            ->assertTableColumnDoesNotExist('is_admin')
            ->assertTableColumnStateSet('roles', [__('Administrator')], record: $admin);
    }
}
