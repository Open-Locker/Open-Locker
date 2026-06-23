<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_compartment_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('compartment_id')->constrained()->cascadeOnDelete();
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'compartment_id']);
            $table->index(['group_id', 'revoked_at', 'expires_at']);
            $table->index(['compartment_id', 'revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_compartment_accesses');
    }
};
