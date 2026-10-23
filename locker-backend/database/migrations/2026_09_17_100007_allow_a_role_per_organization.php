<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A role is held within an organization, so uniqueness is per organization too.
 *
 * The original key predates organizations and says a person holds a role once
 * across the whole installation. Left in place it defeats the point of scoping
 * roles at all: the same person could not be a manager for one operator and an
 * ordinary user for another, which is the case the membership model exists for.
 * The aggregate and projector were already correct — the second grant reached
 * the database and was refused there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_roles', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'role']);
            $table->unique(['user_id', 'organization_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::table('user_roles', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'organization_id', 'role']);
            $table->unique(['user_id', 'role']);
        });
    }
};
