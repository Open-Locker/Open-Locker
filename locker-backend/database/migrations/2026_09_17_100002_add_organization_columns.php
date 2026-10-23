<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns only. They arrive nullable so existing rows stay valid; the backfill
 * fills them and the migration after that makes them binding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locker_banks', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->nullable()->after('id')
                ->constrained()->restrictOnDelete();
        });

        // Carried on the compartment as well as on its bank, so the composite
        // foreign key added later can tie the two together.
        Schema::table('compartments', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->nullable()->after('locker_bank_id');
        });

        // A role is held *within* an organization. platform_admin is the one
        // exception and keeps this null, which the check constraint enforces.
        Schema::table('user_roles', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->nullable()->after('user_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_roles', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id'));
        Schema::table('compartments', fn (Blueprint $t) => $t->dropColumn('organization_id'));
        Schema::table('locker_banks', fn (Blueprint $t) => $t->dropConstrainedForeignId('organization_id'));
    }
};
