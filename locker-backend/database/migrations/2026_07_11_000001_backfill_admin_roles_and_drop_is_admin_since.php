<?php

declare(strict_types=1);

use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_admin_since')) {
            return;
        }

        DB::table('users')
            ->select(['id', 'is_admin_since'])
            ->whereNotNull('is_admin_since')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor((int) $user->id))
                        ->grantRole(
                            userId: (int) $user->id,
                            role: Role::Admin->value,
                            actorUserId: null,
                            grantedAt: Carbon::parse($user->is_admin_since),
                        )
                        ->persist();
                }
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_admin_since');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'is_admin_since')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('is_admin_since')->nullable();
        });

        DB::table('user_roles')
            ->select(['user_id', 'granted_at'])
            ->where('role', Role::Admin->value)
            ->orderBy('user_id')
            ->chunkById(100, function ($roles): void {
                foreach ($roles as $role) {
                    DB::table('users')
                        ->where('id', $role->user_id)
                        ->update(['is_admin_since' => $role->granted_at]);
                }
            }, column: 'user_id');
    }
};
