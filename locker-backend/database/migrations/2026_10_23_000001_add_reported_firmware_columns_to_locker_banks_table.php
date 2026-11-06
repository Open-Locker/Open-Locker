<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locker_banks', function (Blueprint $table): void {
            // What the heartbeat reports (ADR-0076), written directly like modbus_connected.
            // Null until a device that reports firmware has heartbeated.
            $table->string('firmware_version', 31)->nullable();
            $table->boolean('firmware_trial')->nullable();
            $table->string('firmware_running_slot', 5)->nullable();
            $table->string('firmware_last_rollback_version', 31)->nullable();
            $table->timestamp('firmware_reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('locker_banks', function (Blueprint $table): void {
            $table->dropColumn([
                'firmware_version',
                'firmware_trial',
                'firmware_running_slot',
                'firmware_last_rollback_version',
                'firmware_reported_at',
            ]);
        });
    }
};
