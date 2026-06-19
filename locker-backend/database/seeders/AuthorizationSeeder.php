<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Aggregates\RoleAggregate;
use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use App\Models\User;
use App\Support\Authorization\AuthorizationCatalog;
use Illuminate\Database\Seeder;

/**
 * Idempotent. Seeds the default role -> permission bindings from the catalog
 * (config/authorization.yaml) and backfills the admin role for any existing
 * legacy `is_admin_since` admins. Safe to re-run (aggregates dedupe). Run on
 * deploy. See ADR-0021.
 */
class AuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = app(AuthorizationCatalog::class);

        foreach ($catalog->defaultBindings() as $role => $permissions) {
            $aggregate = RoleAggregate::retrieve(RoleAggregate::aggregateUuidFor($role));

            foreach ($permissions as $permission) {
                $aggregate->grantPermission($role, $permission, null, now());
            }

            $aggregate->persist();
        }

        User::query()->whereNotNull('is_admin_since')->each(function (User $user): void {
            UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($user->id))
                ->grantRole($user->id, Role::Admin->value, null, now())
                ->persist();
        });
    }
}
