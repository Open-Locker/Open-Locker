<?php

declare(strict_types=1);

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Give the installation a platform administrator.
 *
 * Without this there is no supported way to create the first one: the
 * first-admin command makes an organization admin, granting platform_admin
 * requires already holding it, and creating organizations requires it too — so
 * turning on multi-organization support would leave an installation unable to
 * add a second operator.
 *
 * Whoever administered the installation before organizations existed was
 * installation-wide by definition, which is exactly what this role means now.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existingAdminIds = DB::table('user_roles')
            ->where('role', Role::Admin->value)
            ->distinct()
            ->pluck('user_id');

        foreach ($existingAdminIds as $userId) {
            DB::table('user_roles')->insertOrIgnore([
                'user_id' => $userId,
                'organization_id' => null,
                'role' => Role::PlatformAdmin->value,
                'granted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('user_roles')->where('role', Role::PlatformAdmin->value)->delete();
    }
};
