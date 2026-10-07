<?php

declare(strict_types=1);

use App\Aggregates\UserRoleAggregate;
use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The half of isolation that does not depend on anyone remembering to apply a
 * scope. Application scopes decide what a query returns; these decide what the
 * database will accept at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The NOT NULL changes work everywhere; the composite foreign key and
        // the check constraint are ALTER TABLE ... ADD CONSTRAINT, which SQLite
        // does not support. Dev and production run PostgreSQL, but the test
        // suite runs SQLite in memory, so on that driver the database half of
        // the isolation simply is not present — see the note in the ADR.
        $supportsAddConstraint = DB::connection()->getDriverName() !== 'sqlite';

        // An unscoped insert now fails instead of creating an orphan that every
        // scope quietly ignores.
        Schema::table('locker_banks', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable(false)->change();
        });

        Schema::table('compartments', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable(false)->change();
        });

        if (! $supportsAddConstraint) {
            return;
        }

        // Referenced by the composite foreign key below. Redundant as a key in
        // its own right — id is already the primary key — but a composite
        // foreign key needs a matching unique index on the parent.
        DB::statement('ALTER TABLE locker_banks ADD CONSTRAINT locker_banks_id_organization_id_unique UNIQUE (id, organization_id)');

        // The constraint that matters: a compartment cannot reference a bank
        // belonging to a different organization. Not "is not queried" —
        // physically cannot be written.
        DB::statement(<<<'SQL'
            ALTER TABLE compartments
            ADD CONSTRAINT compartments_bank_same_organization_foreign
            FOREIGN KEY (locker_bank_id, organization_id)
            REFERENCES locker_banks (id, organization_id)
            ON DELETE CASCADE
        SQL);

        // platform_admin administers the installation and belongs to no
        // organization; every other role belongs to exactly one. Keeping the two
        // in step is the database's job, not a convention.
        DB::statement(<<<'SQL'
            ALTER TABLE user_roles
            ADD CONSTRAINT user_roles_platform_admin_has_no_organization
            CHECK ((role = 'platform_admin') = (organization_id IS NULL))
        SQL);
    }

    /**
     * Rolling back also removes platform_admin from everyone. The role exists
     * only because this migration introduced it and means nothing without
     * multi-organization support, so abandoning the feature must not leave
     * installation-wide access behind.
     *
     * Revoked through the aggregate rather than deleted from `user_roles`: that
     * table is a projection, and an event-sourced grant deleted there would
     * come back on the next replay.
     */
    public function down(): void
    {
        $platformAdminIds = DB::table('user_roles')
            ->where('role', Role::PlatformAdmin->value)
            ->distinct()
            ->pluck('user_id');

        foreach ($platformAdminIds as $userId) {
            $userId = (int) $userId;

            UserRoleAggregate::retrieve(UserRoleAggregate::aggregateUuidFor($userId))
                ->revokeRole($userId, Role::PlatformAdmin->value, null, now(), null)
                ->persist();
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE user_roles DROP CONSTRAINT IF EXISTS user_roles_platform_admin_has_no_organization');
            DB::statement('ALTER TABLE compartments DROP CONSTRAINT IF EXISTS compartments_bank_same_organization_foreign');
            DB::statement('ALTER TABLE locker_banks DROP CONSTRAINT IF EXISTS locker_banks_id_organization_id_unique');
        }

        Schema::table('compartments', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->change();
        });

        Schema::table('locker_banks', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->change();
        });
    }
};
