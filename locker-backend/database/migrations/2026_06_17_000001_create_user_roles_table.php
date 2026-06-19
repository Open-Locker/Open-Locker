<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Read model for user -> role assignments. Written ONLY by UserRoleProjector
 * from UserRoleGranted/Revoked events (ADR-0021); rebuildable by replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role');
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at');
            $table->timestamps();

            $table->unique(['user_id', 'role']);
            $table->index('role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
