<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mqtt_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('locker_bank_id')->constrained()->cascadeOnDelete();
            $table->string('username')->unique();
            $table->string('password_hash');
            $table->boolean('enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mqtt_users');
    }
};
