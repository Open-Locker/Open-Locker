<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read model for organization memberships. Written ONLY by
 * OrganizationMembershipProjector; rebuildable by replay. Every column comes
 * from the events, so a replay reproduces the table exactly. The actor is not
 * a foreign key: deleting them must not change rows that a replay would
 * rebuild with their id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('added_by_user_id')->nullable();
            $table->timestamp('joined_at');

            $table->primary(['user_id', 'organization_id']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
    }
};
