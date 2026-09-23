<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every installation becomes a single-organization installation: one default
 * organization owns everything that exists today, and every current user is a
 * member of it.
 *
 * This is deliberately one-directional. Creating a second organization later
 * never reassigns history, because the events that reference these rows are
 * immutable.
 */
return new class extends Migration
{
    private const DEFAULT_SLUG = 'default';

    public function up(): void
    {
        $organizationId = DB::table('organizations')->where('slug', self::DEFAULT_SLUG)->value('id')
            ?? (string) Str::uuid();

        DB::table('organizations')->insertOrIgnore([
            'id' => $organizationId,
            'name' => 'Default Organization',
            'slug' => self::DEFAULT_SLUG,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('locker_banks')->whereNull('organization_id')->update(['organization_id' => $organizationId]);
        DB::table('compartments')->whereNull('organization_id')->update(['organization_id' => $organizationId]);

        // platform_admin does not exist yet, so every existing role row is an
        // organization role and belongs to the default organization.
        DB::table('user_roles')->whereNull('organization_id')->update(['organization_id' => $organizationId]);

        $memberships = DB::table('users')->pluck('id')->map(fn (int $userId): array => [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        foreach (array_chunk($memberships, 500) as $chunk) {
            DB::table('organization_user')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        $organizationId = DB::table('organizations')->where('slug', self::DEFAULT_SLUG)->value('id');

        if ($organizationId === null) {
            return;
        }

        DB::table('organization_user')->where('organization_id', $organizationId)->delete();
        DB::table('user_roles')->where('organization_id', $organizationId)->update(['organization_id' => null]);
        DB::table('compartments')->where('organization_id', $organizationId)->update(['organization_id' => null]);
        DB::table('locker_banks')->where('organization_id', $organizationId)->update(['organization_id' => null]);
        DB::table('organizations')->where('id', $organizationId)->delete();
    }
};
