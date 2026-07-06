<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Aggregates\RoleAggregate;
use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use App\Models\User;
use App\Support\Authorization\AuthorizationCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the default role -> permission bindings from the catalog
 * (config/authorization.yaml) and backfills the admin role for any existing
 * legacy `is_admin_since` admins. See ADR-0021.
 *
 * Default bindings are seeded per role only on a *fresh* install (no existing
 * binding history for that role). After the first seed the DB is authoritative
 * (ADR-0021, decision 2): admins may revoke a default binding at runtime, so
 * re-running on deploy must NOT resurrect it. Safe to re-run.
 */
class AuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = app(AuthorizationCatalog::class);

        foreach ($catalog->defaultBindings() as $role => $permissions) {
            // Fresh install for this role = no binding history at all. Soft-revoked
            // bindings keep their row, so a role that was granted-then-revoked still
            // counts as "touched" and is left alone.
            if (DB::table('role_permissions')->where('role', $role)->exists()) {
                continue;
            }

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
