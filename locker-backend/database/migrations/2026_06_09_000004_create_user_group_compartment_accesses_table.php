<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Derived effective-access table: the flattened (user x compartment) access
     * produced by GroupProjector from active membership x active group grants.
     * Never written by hand. A row simply does not exist when no active source
     * grants it, so there is no revoked_at column here.
     */
    public function up(): void
    {
        Schema::create('user_group_compartment_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('compartment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'compartment_id']);
            $table->index(['user_id', 'expires_at']);
            $table->index(['compartment_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_group_compartment_accesses');
    }
};
