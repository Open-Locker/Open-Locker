<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compartment_accesses', function (Blueprint $table): void {
            $table->foreignId('granted_by_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('revoked_by_user_id')
                ->nullable()
                ->after('granted_by_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compartment_accesses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('granted_by_user_id');
            $table->dropConstrainedForeignId('revoked_by_user_id');
        });
    }
};
