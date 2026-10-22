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
            // Whether the heartbeat lists the `firmware_update` capability (ADR-0076).
            $table->boolean('firmware_update_capable')->default(false);
            // The update in flight or last finished (ADR-0077): requested, downloading or failed.
            $table->string('firmware_update_status')->nullable();
            $table->string('firmware_update_transaction_id')->nullable();
            // The release being installed: only a bank updating to it may download its image.
            $table->uuid('firmware_update_release_id')->nullable();
            $table->string('firmware_update_target_version', 31)->nullable();
            $table->string('firmware_update_error_code')->nullable();
            $table->timestamp('firmware_update_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('locker_banks', function (Blueprint $table): void {
            $table->dropColumn([
                'firmware_update_capable',
                'firmware_update_status',
                'firmware_update_transaction_id',
                'firmware_update_release_id',
                'firmware_update_target_version',
                'firmware_update_error_code',
                'firmware_update_requested_at',
            ]);
        });
    }
};
